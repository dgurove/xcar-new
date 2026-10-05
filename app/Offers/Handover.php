<?php

namespace App\Offers;

use App\Mail\Extraction\Patterns;
use App\Park\VehicleState;
use App\Support\Phone;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Support\Carbon;

/**
 * Получение автомобиля по сделке (04.10.2026, владелец: «согласовали, он должен вывезти, а у него 0 инфы»): где стоит
 * ТС, кто её забирает, с кем говорить, адрес и когда, и можно ли уже нажать «Забрал». Одним расчётом на страницу сделки
 * менеджера и карточку сделки в CRM.
 *
 * Открывается после «Поставщик согласовал» (решение владельца), «Забрал» — только где маршрут разрешает: шаг продажи
 * «Автомобиль забрал» (он в просьбе шага) или «Забрал» вывоза, когда у продажи такого шага нет (Альфа).
 *
 * Откуда: поля текущего шага продажи (их вписали менеджеру), поля «Вывоза и осмотра», парковка, где стоит ТС, и
 * наконец само предложение — страхователь и адрес осмотра из письма. Контакт владельца менеджеру — только когда
 * забирает он сам или шаг продажи его и просит (Т-Страхование): забираем мы — сводить его с владельцем не нужно.
 * Письмо целиком менеджер не видит никогда: в нём закупочная.
 */
final class Handover
{
    /** Подписи полей шага — что они значат. Поля маршрутов вписаны людьми, поэтому по подписи, а не по ключу. */
    private const NAME = ['владелец', 'контакт', 'контакт на площадке'];

    private const PHONE = ['телефон владельца', 'телефон'];

    private const ADDRESS = ['адрес автомобиля', 'адрес', 'адрес площадки'];

    private const DATE = ['дата вывоза', 'дата выдачи'];

    /** Выходы, которыми ТС переходит к менеджеру. */
    public const PICKED = ['автомобиль забрал', 'забрал'];

    public function __construct(
        public readonly bool $open,
        public readonly bool $buyerPicks,
        public readonly ?string $where,
        public readonly bool $withBuyer,
        public readonly ?string $name,
        public readonly ?string $phone,
        public readonly ?string $address,
        public readonly ?string $mapUrl,
        public readonly ?string $date,
        public readonly ?Outcome $saleExit,
        public readonly bool $servicePick,
        /** @var list<string> ключи полей шага продажи, что показаны здесь: в шаге второй раз не пишутся */
        public readonly array $keys,
    ) {}

    public static function for(Deal $deal): self
    {
        $offer = $deal->offer->loadMissing(['positions.stage.exits', 'parkVehicle.yard.settlement', 'settlement']);
        $sale = $offer->position(Track::Sale);
        $service = $offer->position(Track::Service);
        $picks = $deal->buyerPicksUp();
        $place = $service?->stage->car_place;

        $saleExit = $sale?->stage->exitsFor(Actor::Manager, $deal)->first(fn (Outcome $e) => self::picked($e));
        // «Забрал» вывоза — только когда у продажи своего шага получения нет вовсе (Альфа): есть — жмут там и в свой черёд
        // (06.10.2026, Т-Страхование: сначала «Связался», потом «Забрал»).
        $routePicks = $sale && Stage::where('workflow_id', $sale->stage->workflow_id)->with('exits')->get()
            ->contains(fn (Stage $s) => $s->exitsFor(Actor::Manager, $deal)->contains(fn (Outcome $e) => self::picked($e)));
        $servicePick = $picks && ! $routePicks && $service && $service->stage->exitsFor(Actor::Keeper, $offer->pickupDestination())->isNotEmpty();
        // Шаг продажи сам про получение: просит забрать (Каркаде, гараж, «Менеджер забирает автомобиль») или вписали ему,
        // где и у кого (Т-Страхование).
        $saleAsks = $saleExit || ($sale && $sale->stage->awaitsManager($deal) && self::mapped($sale->stage) !== []);

        $fields = [];
        $keys = [];
        if ($sale) {
            foreach (self::mapped($sale->stage) as $key => $kind) {
                if (filled($v = $sale->payload[$key] ?? null)) {
                    $fields[$kind] ??= (string) $v;
                    $keys[] = $key;
                }
            }
        }
        $owner = $picks || $saleAsks;
        if ($owner && $service) {
            foreach (self::mapped($service->stage) as $key => $kind) {
                if (filled($v = $service->payload[$key] ?? null)) {
                    $fields[$kind] ??= (string) $v;
                }
            }
        }
        // «Контакт» бывает строкой «Валентин Сергеевич +7 905 …»: телефон — отдельно, чтобы по нему звонили.
        if (isset($fields['name']) && ! isset($fields['phone']) && ($phones = Patterns::phones($fields['name']))) {
            $fields['phone'] = $phones[0];
            $fields['name'] = trim((string) preg_replace('/'.Patterns::PHONE.'/u', '', $fields['name']), " \t,;–—-") ?: null;
        }

        $vehicle = $offer->parkVehicle;
        $atYard = $vehicle?->state === VehicleState::Stored && $vehicle->yard;
        $where = match (true) {
            $place === CarPlace::Keeper => $offer->keeper()?->id === $deal->buyer_id ? 'У вас' : CarPlace::WithUs->label(),
            $place === CarPlace::WithUs => CarPlace::WithUs->label(),
            $place === CarPlace::Ours || $atYard => 'На парковке',
            $place === CarPlace::Moving && ! $picks && $offer->pickupDestination() === Destination::Yard => 'Везём на парковку',
            $sale && ! $service && $sale->stage->workflow->stages->contains(fn (Stage $s) => in_array('адрес площадки', self::labels($s), true)) => 'На площадке поставщика',
            $place === CarPlace::Owner, $place === CarPlace::Moving, $offer->insured_name || $offer->insured_phone => 'У владельца',
            default => null,
        };
        $withBuyer = $where === 'У вас';

        $mapUrl = null;
        if ($atYard && ! $withBuyer) {
            $fields['address'] ??= $vehicle->yard->fullAddress();
            $mapUrl = $vehicle->yard->mapUrl();
        }
        if ($owner && ! $withBuyer) {
            $fields['name'] ??= $offer->insured_name;
            $fields['phone'] ??= $offer->insured_phone;
            $fields['address'] ??= $offer->inspection_address ?: null;
        }
        $phone = Phone::normalize($fields['phone'] ?? null);
        $address = $withBuyer ? null : ($fields['address'] ?? null);
        if ($address && ! $mapUrl) {
            // Адрес из письма часто без города: город предложения впереди, иначе карта ищет улицу по всей стране.
            $city = $offer->settlement?->name;
            $mapUrl = 'https://yandex.ru/maps/?text='.rawurlencode($city && ! str_contains($address, $city) ? "{$city}, {$address}" : $address);
        }

        // Ждём согласия поставщика — рано: продажа может не состояться, контакт владельца менеджеру ещё не нужен.
        $waiting = $sale && $sale->stage->exits->contains(fn (Outcome $e) => str_starts_with(mb_strtolower($e->label), 'поставщик согласовал'));
        $open = $deal->isActive() && ! $deal->isGarage() && ! $waiting;

        return new self(
            open: $open,
            buyerPicks: $picks,
            where: $where,
            withBuyer: $withBuyer,
            name: $withBuyer ? null : ($fields['name'] ?? null),
            phone: $withBuyer ? null : $phone,
            address: $address,
            mapUrl: $address ? $mapUrl : null,
            date: $withBuyer ? null : ($fields['date'] ?? null),
            saleExit: $open ? $saleExit : null,
            servicePick: $open && $servicePick,
            keys: $keys,
        );
    }

    /** Есть что показать: место, контакт или адрес. Пустой блок не рисуется. */
    public function shows(): bool
    {
        return $this->open && ($this->where || $this->name || $this->phone || $this->address);
    }

    /** Текущая просьба шага и есть «заберите»: карточка получения встаёт внутрь шага, а не отдельной. */
    public function inStep(?Position $sale, ?Deal $deal = null): bool
    {
        return $this->open && $sale && ($this->saleExit || ($sale->stage->awaitsManager($deal) && self::mapped($sale->stage) !== []));
    }

    public function phoneFormatted(): ?string
    {
        return $this->phone ? Phone::format($this->phone) : null;
    }

    /** Дата словами, если это дата: «12 октября». */
    public function dateLabel(): ?string
    {
        if (! $this->date) {
            return null;
        }
        try {
            return preg_match('/^\d{4}-\d{2}-\d{2}/', $this->date) ? Carbon::parse($this->date)->translatedFormat('j F') : $this->date;
        } catch (\Throwable) {
            return $this->date;
        }
    }

    /**
     * Чем заполнить поля шага, пока их не вписали (шторка исхода в CRM): страхователь, телефон и адрес из письма. Поля
     * площадки поставщика (Каркаде) — не про страхователя, их не трогаем. «Контакт» без отдельного телефона — имя с ним.
     *
     * @return array<string, string>
     */
    public static function prefill(Stage $stage, Offer $offer): array
    {
        $labels = collect($stage->staff_fields ?? [])->mapWithKeys(fn ($f) => [$f['key'] ?? '' => mb_strtolower(trim((string) ($f['label'] ?? '')))]);
        $mapped = self::mapped($stage);
        $phoneField = in_array('phone', $mapped, true);
        $out = [];
        foreach ($mapped as $key => $kind) {
            if (in_array($labels[$key] ?? '', ['контакт на площадке', 'адрес площадки'], true)) {
                continue;
            }
            $value = match ($kind) {
                'name' => $phoneField ? $offer->insured_name : trim($offer->insured_name.' '.$offer->insured_phone),
                'phone' => $offer->insured_phone,
                'address' => $offer->inspection_address,
                default => null,
            };
            if (filled($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Получение по ДКП чек-листом (06.10.2026, Т-Страхование): шаги «Связался с владельцем» → «Автомобиль забрал» →
     * «Подписанный договор» — где сейчас сделка и что уже сделано. Не этот маршрут или сделка не на этих шагах — null.
     *
     * @return array{contact: string, pickup: string, signed: string}|null  done | current | later
     */
    public static function checklist(Deal $deal, ?Position $sale): ?array
    {
        if (! $sale || ! $deal->isActive() || ! $deal->hasContract() || $deal->isPrime()) {
            return null;
        }
        $stages = Stage::where('workflow_id', $sale->stage->workflow_id)->with('exits')->get();
        $contact = $stages->first(fn (Stage $s) => $s->exitsFor(Actor::Manager, $deal)->contains(fn (Outcome $e) => mb_strtolower(trim($e->label)) === 'связался с владельцем'));
        $pick = $stages->first(fn (Stage $s) => $s->exitsFor(Actor::Manager, $deal)->contains(fn (Outcome $e) => self::picked($e)));
        $signed = $pick ? $stages->firstWhere('id', $pick->exitsFor(Actor::Manager, $deal)->first(fn (Outcome $e) => self::picked($e))->to_stage_id) : null;
        $at = $sale->stage_id;
        if (! $contact || ! $signed || ! in_array($at, [$contact->id, $pick->id, $signed->id], true)) {
            return null;
        }

        return [
            'contact' => $at === $contact->id ? 'current' : 'done',
            'pickup' => match ($at) { $pick->id => 'current', $contact->id => 'later', default => 'done' },
            'signed' => $at === $signed->id ? 'current' : 'later',
        ];
    }

    public static function picked(Outcome $exit): bool
    {
        return in_array(mb_strtolower(trim($exit->label)), self::PICKED, true);
    }

    /** @return array<string, string> ключ поля шага → name | phone | address | date */
    private static function mapped(Stage $stage): array
    {
        $out = [];
        foreach ($stage->staff_fields ?? [] as $f) {
            $label = mb_strtolower(trim((string) ($f['label'] ?? '')));
            $kind = match (true) {
                in_array($label, self::PHONE, true) => 'phone',
                in_array($label, self::NAME, true) => 'name',
                in_array($label, self::ADDRESS, true) => 'address',
                in_array($label, self::DATE, true) => 'date',
                default => null,
            };
            if ($kind && isset($f['key'])) {
                $out[$f['key']] = $kind;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function labels(Stage $stage): array
    {
        return array_map(fn ($f) => mb_strtolower(trim((string) ($f['label'] ?? ''))), $stage->staff_fields ?? []);
    }
}
