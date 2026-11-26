<?php

namespace App\Offers;

use App\Cars\Car;
use App\Cars\Sources\Collect;
use App\Mail\Attachment;
use App\Mail\Direction;
use App\Mail\Extraction\DocumentFields;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Offers\Actions\DropValuation;
use App\Offers\Actions\UpdateOffer;
use App\Park\Actions\UpdateVehicle;
use App\Support\Ago;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Какая оценочная верна — самая свежая по дате источника (15.11.2026, владелец по 0790/046/10760/26: «18 октября Альфа прислала
 * оценку 6 млн, 26 октября — заявку на приём, в ней уже 3 млн. Получается, это более свежая оценка! Мы оценки с парковки
 * отметали и опирались на оценку от Альфы, но это был неверный подход»). Тип источника ничего не решает, Мигторг сам по себе
 * решает дата, на которую сумма верна (`offers.value_at`, `cars.value_at`):
 * - заявка поставщика во вложении письма — дата документа («от «23» октября 2026»), не прочлась — дата письма;
 * - письмо о переоценке — дата письма (`DetectReprice`);
 * - «Оценить из текста», бот, правка рукой — момент записи.
 * Мигторг — тоже дата: лот появился позже оценочной — она неактуальна (владелец, тот же день: «появление на Мигторге произошло
 * ПОСЛЕ заявки на приём, а значит та оценка уже не актуальна»; Альфа выставляет машину на торги и потом присылает новую
 * оценку). Такая не встаёт (`older`), а стоящая снимается, когда лот появился (`sweep`, часы `migtorg:sync`); новая оценочная
 * после лота («Оценить из текста», бот, свежая заявка) встаёт как обычно. Появление лота — `migtorg_lots.first_seen_at`
 * (`lotAt`).
 *
 * Пишет только `UpdateOffer` (`$log['as_of']`): оценочная старше нынешней не встаёт (`older`), закупочная идёт за оценочной по
 * правилу, если не вписана рукой, при подтвердивших — переоценка на решение. Документы машины — после каждого чтения
 * (`Collect` → `fromDocuments`): своя заявка поставщика новее нынешней оценочной — она и встаёт.
 */
final class Valuations
{
    /** Где оценочная продажи ещё что-то решает: дальше сделка держит свою закупочную. */
    private const LIVE = [OfferState::Draft, OfferState::Gallery, OfferState::Open];

    /** На какой день верна нынешняя оценочная продажи. До `value_at` — последняя правка оценочной в «Истории». */
    public static function at(Offer $offer): ?Carbon
    {
        // Оценочную сняли как неактуальную (`DropValuation`) — её дата остаётся: что старше, не встаёт.
        if ($offer->value === null) {
            return $offer->value_at;
        }
        if ($offer->value_at) {
            return $offer->value_at;
        }
        $at = $offer->id ? DB::table('car_events')->where('offer_id', $offer->id)->whereRaw("jsonb_exists(payload->'changes', 'value')")->max('created_at') : null;

        return $at ? Carbon::parse($at) : $offer->created_at;
    }

    /** На какой день верна оценочная ТС парковки. До `value_at` — правка на парковке, заявка, правка машины в «Истории». */
    public static function carAt(Car $car): ?Carbon
    {
        if (! $car->value) {
            return null;
        }
        if ($car->value_at) {
            return $car->value_at;
        }
        // Последняя правка оценочной — на парковке или у продажи (до 15.11.2026 «Оценить из текста» писала и в ТС); правок не
        // было — она с заявки на приём.
        $edits = array_filter([
            DB::table('park_vehicle_events')->join('park_vehicles', 'park_vehicles.id', '=', 'park_vehicle_events.vehicle_id')
                ->where('park_vehicles.car_id', $car->id)->whereRaw("jsonb_exists(park_vehicle_events.payload->'changes', 'value')")->max('park_vehicle_events.created_at'),
            DB::table('car_events')->where('car_id', $car->id)->whereRaw("jsonb_exists(payload->'changes', 'value')")->max('created_at'),
        ]);
        $at = $edits ? max($edits)
            : DB::table('park_requests')->join('park_vehicles', 'park_vehicles.id', '=', 'park_requests.vehicle_id')->where('park_vehicles.car_id', $car->id)->min('park_requests.letter_at');

        return $at ? Carbon::parse($at) : $car->created_at;
    }

    /**
     * Когда машина с этим номером убытка последний раз появилась на Мигторге (новый лот — новые торги). Дата начала лота у
     * Мигторга не отдаётся: `first_seen_at` — первая встреча синхронизации; у лота из архива её нет — дата ближайшего более
     * нового лота с известной датой (номера идут по порядку: появился не позже), иначе «видели последний раз».
     */
    public static function lotAt(?string $claimRefKey): ?Carbon
    {
        if ($claimRefKey === null || $claimRefKey === '') {
            return null;
        }
        $at = null;
        foreach (DB::table('migtorg_lots')->where('claim_ref_key', $claimRefKey)->get(['id', 'first_seen_at', 'seen_at']) as $lot) {
            $seen = $lot->first_seen_at ?? DB::table('migtorg_lots')->where('id', '>=', $lot->id)->min('first_seen_at') ?? $lot->seen_at;
            $seen && ($at === null || $seen > $at) && $at = $seen;
        }

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * Сумма на `$asOf` раньше появления лота на Мигторге — неактуальна. Час допуска: черновик с лота и оценочная из СМС
     * заводятся одним движением («Оценить из текста» → «Нет в CRM» → лот), и дата лота у нас — не раньше этого движения.
     */
    public static function beforeLot(?string $claimRefKey, ?Carbon $asOf): bool
    {
        $lot = $asOf ? self::lotAt($claimRefKey) : null;

        return $lot && $asOf->lt($lot->copy()->subHour());
    }

    /** Сумма на `$asOf` старше нынешней оценочной или появления лота на Мигторге — не встаёт. */
    public static function older(Offer $offer, ?Carbon $asOf): bool
    {
        $at = self::at($offer);

        return $asOf && (($at && $asOf->lt($at)) || self::beforeLot($offer->claim_ref_key, $asOf));
    }

    /** Нынешняя оценочная продажи раньше появления лота — неактуальна. */
    public static function stale(Offer $offer): bool
    {
        return $offer->value !== null && self::beforeLot($offer->claim_ref_key, self::at($offer));
    }

    /**
     * Снять неактуальную оценочную (`stale`) и закупочную, посчитанную от неё (вписанная рукой остаётся); цена продажи остаётся —
     * черновик встаёт в «Без закупочной». Только черновик без слота, аукциона и подтверждений: опубликованное и сделки — решение
     * человека. Сняли — true.
     */
    public static function drop(Offer $offer): bool
    {
        if (! self::stale($offer) || ! DropValuation::allows($offer)) {
            return false;
        }
        $at = self::at($offer);
        app(DropValuation::class)($offer, null, 'Появилось на Мигторге после оценочной'.($at ? ' от '.Ago::day($at) : '').' — она неактуальна');

        return true;
    }

    /**
     * Черновики с оценочной, у которых лот появился позже неё (часы `migtorg:sync`, смена номера убытка, `offers:valuations`).
     *
     * @return Collection<int, Offer>
     */
    public static function staleDrafts(): Collection
    {
        return Offer::query()->where('offers.state', OfferState::Draft)->where('offers.value', '>', 0)
            ->whereNull('offers.slot_at')->whereNull('offers.auction_at')
            ->whereCar(fn ($c) => $c->whereExists(fn ($q) => $q->from('migtorg_lots')->whereColumn('migtorg_lots.claim_ref_key', 'cars.claim_ref_key')
                ->whereRaw('coalesce(migtorg_lots.first_seen_at, migtorg_lots.seen_at) > coalesce(offers.value_at, offers.created_at)')))
            ->get()->filter(fn (Offer $o) => self::stale($o))->values();
    }

    public static function sweep(): int
    {
        return self::staleDrafts()->filter(fn (Offer $o) => self::drop($o))->count() + self::staleIntakes();
    }

    /**
     * Черновик из заявки с парковки (`park_intake_at`), у которого оценочной ещё нет, а лот на Мигторге появился позже заявки
     * (26.11.2026): сумма заявки по правилу Мигторга уже неактуальна и не встанет (`older`) — метка снимается, машина ждёт оценку
     * поставщика в «Без закупочной», как те, у кого оценочную сняло правило (владелец).
     */
    private static function staleIntakes(): int
    {
        $n = 0;
        $flagged = Offer::query()->where('offers.state', OfferState::Draft)->whereNotNull('offers.park_intake_at')->whereNull('offers.value')
            ->whereCar(fn ($c) => $c->whereExists(fn ($q) => $q->from('migtorg_lots')->whereColumn('migtorg_lots.claim_ref_key', 'cars.claim_ref_key')))->get();
        foreach ($flagged as $offer) {
            $lot = self::lotAt($offer->claim_ref_key);
            if ($lot && $lot->gt($offer->park_intake_at)) {
                $offer->forceFill(['park_intake_at' => null])->save();
                $offer->log(OfferEventType::Updated, null, ['why' => 'Появилось на Мигторге после заявки на приём, ждём оценку поставщика']);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Оценочные из заявок поставщика по машине (`DocumentFields::isRequest`: акт несёт нашу же оценочную) — вложения входящих
     * писем её веток, наши ответы не в счёт; только прочитанный текст: кеш чтения или текстовый слой PDF, OCR здесь не запускается.
     *
     * @return Collection<int, array{value: int, as_of: Carbon, title: string, message_id: int}>
     */
    public static function documents(Car $car): Collection
    {
        $out = collect();
        foreach (Collect::files($car) as $file) {
            if (! $file instanceof Attachment || $file->isPhoto()) {
                continue;
            }
            $message = $file->message;
            if (! $message || $message->direction !== Direction::In || $message->isOurs()) {
                continue;
            }
            $text = $file->isImage() ? DocumentText::cached($file) : DocumentText::layer($file);
            $fields = $text && DocumentFields::isRequest($text) ? DocumentFields::extract($text) : [];
            $value = (int) ($fields['value']['value'] ?? 0);
            if ($value < Pricing::MIN || ScanFields::otherCar($car->vin, $car->brand_id, $fields['vin']['value'] ?? null, $fields['brand']['value'] ?? null)) {
                continue;
            }
            $letter = $message->date_at ?? $message->created_at;
            $dated = DocumentFields::dated((string) $text);
            $asOf = $dated && $dated->lte($letter) && $dated->gt($letter->copy()->subMonths(6)) ? $dated : $letter;
            $out->push(['value' => $value, 'as_of' => $asOf, 'title' => 'заявка от '.Ago::day($asOf), 'message_id' => $message->id]);
        }

        // Одна сумма в нескольких письмах (пересылка, «ч.2») — её самая ранняя дата: пересылка не делает оценку свежее.
        return $out->groupBy('value')->map(fn (Collection $g) => $g->sortBy('as_of')->first())->sortBy('as_of')->values();
    }

    /** Самая свежая оценочная из документов поставщика. */
    public static function latestDocument(Car $car): ?array
    {
        return self::documents($car)->last();
    }

    /**
     * Что сделать с продажей по документам: новая оценочная, если заявка поставщика свежее нынешней и сумма другая. Только у
     * вендора «Закупочная от оценочной» (Альфа): у остальных оценочная цен не двигает. Сделка — нет: там своя закупочная.
     *
     * @return array{value: int, as_of: Carbon, title: string, message_id: int, was: ?int, was_at: ?Carbon}|null
     */
    public static function proposal(Offer $offer): ?array
    {
        if (! in_array($offer->state, self::LIVE, true) || ! Pricing::byValue($offer->vendor_id) || $offer->costDeal()) {
            return null;
        }
        $doc = self::latestDocument($offer->carRecord());
        if (! $doc || (int) $offer->value === $doc['value'] || self::older($offer, $doc['as_of']) || (self::at($offer) && $doc['as_of']->eq(self::at($offer)))) {
            return null;
        }

        return $doc + ['was' => $offer->value === null ? null : (int) $offer->value, 'was_at' => self::at($offer)];
    }

    /**
     * Оценочная ТС парковки (ставка хранения) из документов: заявка поставщика свежее нынешней, а нынешнюю не вписывал человек.
     *
     * @return array{value: int, as_of: Carbon, title: string, message_id: int, was: ?int}|null
     */
    public static function parkProposal(Car $car): ?array
    {
        $vehicle = $car->vehicle;
        if (! $vehicle || $vehicle->state->isFinal() || $car->byHuman('value')) {
            return null;
        }
        $doc = self::latestDocument($car);
        $at = self::carAt($car);
        if (! $doc || (int) $car->value === $doc['value'] || ($car->value && $at && $doc['as_of']->lte($at))) {
            return null;
        }

        return $doc + ['was' => $car->value ? (int) $car->value : null];
    }

    /**
     * Поставить оценочную из документов, где она свежее: продаже (`proposal`) и ТС парковки (`parkProposal`). Сначала продажа —
     * правка парковки идёт в продажу (`ParkValue::follow`), и та сумма там уже стоит. Зовёт `Collect` после чтения документов.
     */
    public static function fromDocuments(Car $car): void
    {
        try {
            if (($offer = $car->offer) && ($p = self::proposal($offer))) {
                app(UpdateOffer::class)($offer, ['value' => $p['value']], null, ['source' => 'file', 'as_of' => $p['as_of'], 'why' => self::why($p, $p['was_at'])]);
            }
            $car->refresh();
            if ($p = self::parkProposal($car)) {
                app(UpdateVehicle::class)($car->vehicle, ['value' => $p['value']], null, [self::why($p, $car->value_at)], $p['as_of']);
            }
        } catch (Throwable $e) {
            Log::warning('Оценочная из документов не встала', ['car' => $car->id, 'error' => $e->getMessage()]);
        }
    }

    private static function why(array $p, ?Carbon $wasAt): string
    {
        return 'Оценочная по '.$p['title'].($p['was'] !== null ? ', свежее прежней '.Money::rub($p['was']).($wasAt ? ' от '.Ago::day($wasAt) : '') : '');
    }
}
