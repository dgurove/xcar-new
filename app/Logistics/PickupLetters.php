<?php

namespace App\Logistics;

use App\Mail\Template;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Письма поставщику о вывозе (23.11.2026, разбор писем Совкомбанка: «когда вывоз?», «сообщите за сутки», «пришлите доверенность
 * на представителя», «вывезли? пришлите акт» — висели неразобранными). У вендора с галкой «Письма о вывозе» (`vendors.pickup_letters`)
 * вывоз от владельца даёт два дела:
 * - есть дата вывоза (`Call::agree`, «Перенести» на другой день — снова) — «Сообщить поставщику дату вывоза»: шаблон
 *   «Поставщику: дата вывоза», к письму — постоянные файлы шаблона (доверенность, `Template` медиа `files`);
 * - «Забрал» — «Отправить поставщику акт вывоза»: шаблон «Поставщику: ТС вывезено», к письму — акт и соглашение, приложенные к
 *   машине с созвона (`Template::PICKUP_ACT`).
 * Дело закрывает письмо из окна (скрытое `pickup_letter`, `MailController::send` → `Actions\MarkPickupLetter`; не ушло —
 * дело снова, `MarkPickupLetter::failed`) или «Без письма — сообщили сами». Шаблоны находят по метке вложения (`Template::PICKUP_*`). Видно в дорожке
 * «Вывоз» окна и страницы, у идущей сделки — делом «Написать поставщику» (`Position::pickupLetter`, `NEEDS_US_SQL`), админам —
 * уведомлением, когда дело появилось (`TransportMoved`).
 */
final class PickupLetters
{
    public const DATE = 'Поставщику: дата вывоза';

    public const ACT = 'Поставщику: ТС вывезено';

    /** Шаблоны по умолчанию — заводятся миграцией, дальше правятся в настройках почты. */
    public const LETTERS = [
        'date' => ['name' => self::DATE, 'subject' => '{{ claim_ref }}', 'attach' => Template::PICKUP_DATE,
            'body' => "Здравствуйте!\n\nВывоз {{ car }}, VIN {{ vin }} ({{ claim_ref }}): {{ pickup_at }}, {{ pickup_place }}.\nЗабирает {{ driver }}. Доверенность во вложении.\n\nС уважением,\nАндрей Кузнецов"],
        'act' => ['name' => self::ACT, 'subject' => '{{ claim_ref }}', 'attach' => Template::PICKUP_ACT,
            'body' => "Здравствуйте!\n\n{{ car }}, VIN {{ vin }} ({{ claim_ref }}) вывезено {{ picked_at }}. Акт приёма-передачи во вложении.\n\nС уважением,\nАндрей Кузнецов"],
    ];

    /** Документ машины — акт или соглашение: прикладывается к письму о вывозе. */
    private const ACT_FILE = '/акт|апп|соглашени|приема|приёма|передач/iu';

    /**
     * Дело одним условием над вывозом `pt` и машиной `po` (вендор с галкой). Миграция `pickup_letters` закрыла прошлое: дата — у
     * вывозов, чей день прошёл; акт — у забранных раньше недели.
     */
    public const DUE_SQL = "exists (select 1 from offers po join vendors pv on pv.id = po.vendor_id and pv.pickup_letters
        join transports pt on pt.car_id = po.car_id and pt.id = (".self::LATEST_SQL.")
        where po.id = offer_positions.offer_id and ((pt.picked_at is not null and pt.supplier_act_at is null)
            or (pt.picked_at is null and pt.pickup_at is not null and pt.supplier_told_for is distinct from pt.pickup_at)))";

    /** Вывоз от владельца — последний живой вывоз продажи машины `pt` (как `Offer::pickupTask`): старые дел не дают. */
    public const LATEST_SQL = "select max(t2.id) from transports t2 where t2.car_id = pt.car_id and t2.origin = 'sale' and t2.kind = 'pickup' and t2.state <> 'cancelled'";

    /**
     * Какое письмо ждёт у машины: вид, дело словом, с какого момента, вывоз и адрес окна письма. Нет — null.
     *
     * @return array{kind: string, title: string, since: Carbon, transport: Transport, url: ?string}|null
     */
    public static function due(Offer $offer): ?array
    {
        if (! $offer->vendor?->pickup_letters || ! ($t = $offer->pickupTask) || $t->state === TransportState::Cancelled) {
            return null;
        }
        $kind = self::kindOf($t);
        if (! $kind) {
            return null;
        }
        // По метке вложения, а не названию: его правят в настройках почты.
        $template = Template::where('scope', \App\Mail\Scope::Offers)->where('attach', $kind === 'act' ? Template::PICKUP_ACT : Template::PICKUP_DATE)->orderBy('id')->value('id');

        return [
            'kind' => $kind,
            'title' => $kind === 'act' ? 'Отправить поставщику акт вывоза' : 'Сообщить поставщику дату вывоза',
            'since' => $kind === 'act' ? $t->picked_at : ($t->agreed_at ?? $t->updated_at),
            'transport' => $t,
            'url' => $template ? "/work/mail/new?offer={$offer->number}&template={$template}&pickup={$t->id}:{$kind}" : null,
        ];
    }

    public static function kindOf(Transport $t): ?string
    {
        return match (true) {
            $t->picked_at !== null => $t->supplier_act_at === null ? 'act' : null,
            $t->pickup_at !== null => $t->supplier_told_for?->equalTo($t->pickup_at) ? null : 'date',
            default => null,
        };
    }

    /**
     * Ждущие письма о вывозе по машинам с идущей сделкой: offer_id → [вид, с какого момента] — строки «Сделок» спрашивают одним
     * запросом (как `ReplyReview::waiting`). @return Collection<int, array{kind: string, since: Carbon}>
     */
    public static function waiting(): Collection
    {
        $load = fn () => Transport::query()->from('transports as pt')->join('offers as o', 'o.car_id', '=', 'pt.car_id')
            ->join('vendors as v', 'v.id', '=', 'o.vendor_id')->where('v.pickup_letters', true)
            ->whereRaw('pt.id = ('.self::LATEST_SQL.')')
            ->whereExists(fn ($q) => $q->from('deals as d')->whereColumn('d.offer_id', 'o.id')->whereRaw(Deal::ACTIVE_D))
            ->where(fn ($q) => $q->where(fn ($a) => $a->whereNotNull('pt.picked_at')->whereNull('pt.supplier_act_at'))
                ->orWhere(fn ($d) => $d->whereNull('pt.picked_at')->whereNotNull('pt.pickup_at')
                    ->whereRaw('pt.supplier_told_for is distinct from pt.pickup_at')))
            ->get(['o.id as offer_id', 'pt.*'])
            ->mapWithKeys(fn (Transport $t) => [(int) $t->offer_id => ['kind' => self::kindOf($t) ?? 'date',
                'since' => $t->picked_at ?? $t->agreed_at ?? $t->updated_at]]);

        return app()->runningInConsole() ? $load() : once($load);
    }

    /**
     * Вывозы, чьё письмо поставщику ждёт, у любой машины, не только в сделке (24.11.2026, вкладка «Сообщить поставщику» раздела
     * «Вывоз» — `Admin\PickupController`): черновик Совкомбанка вывозят до продажи. @return list<int>
     */
    public static function dueTransportIds(?string $kind = null): array
    {
        // `$kind` — только акты (`act`) или только дата (`date`): красное число вкладки — дата, акт ждёт тихо (27.11.2026, владелец:
        // «акты долго доставать, это норм»).
        $act = fn ($a) => $a->whereNotNull('pt.picked_at')->whereNull('pt.supplier_act_at');
        $date = fn ($d) => $d->whereNull('pt.picked_at')->whereNotNull('pt.pickup_at')->whereRaw('pt.supplier_told_for is distinct from pt.pickup_at');
        $load = fn () => Transport::query()->from('transports as pt')->join('offers as o', 'o.car_id', '=', 'pt.car_id')
            ->join('vendors as v', 'v.id', '=', 'o.vendor_id')->where('v.pickup_letters', true)
            ->whereRaw('pt.id = ('.self::LATEST_SQL.')')
            ->where(fn ($q) => match ($kind) {
                'act' => $q->where($act),
                'date' => $q->where($date),
                default => $q->where($act)->orWhere($date),
            })
            ->pluck('pt.id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        return app()->runningInConsole() ? $load() : once($load);
    }

    /**
     * Ход вывоза (`TransportMoved`): назначили день («Созвонились», «Перенести») или забрали — дело письма появилось, админам
     * уведомление. Задним числом («Уже вывезли», `batch`) — без него.
     */
    public static function moved(\App\Logistics\Events\TransportMoved $e): void
    {
        if ($e->batch || ! in_array($e->step, ['agreed', 'picked'], true) || ! ($offer = $e->transport->saleOffer())) {
            return;
        }
        $offer->unsetRelation('pickupTask');
        if (($due = self::due($offer)) && $due['kind'] === ($e->step === 'picked' ? 'act' : 'date')) {
            \App\Notifications\Notify::toDeals(\App\Notifications\DealEventNotice::pickupLetter($offer, $due['title'], $e->by));
        }
    }

    /** Подстановки письма о вывозе из вывоза машины от владельца. @return array<string, string> */
    public static function placeholders(Offer $offer): array
    {
        $t = $offer->pickupTask;
        if (! $t) {
            return [];
        }
        // Кто едет: исполнитель (водитель), иначе менеджер, что забирает сам.
        $driver = $t->executor ?? ($t->isSelfPickup() ? $offer->deal?->buyer : null);

        return [
            'pickup_at' => $t->pickup_at ? $t->pickup_at->format('d.m.Y') : '',
            'pickup_place' => (string) ($t->pickup_place ?? $t->from_address ?? ''),
            'driver' => $driver ? trim($driver->name.($driver->phone ? ', +'.ltrim($driver->phone, '+') : '')) : '',
            'picked_at' => $t->picked_at ? $t->picked_at->format('d.m.Y') : '',
        ];
    }

    /**
     * Файлы письма: постоянные файлы шаблона (доверенность), а у письма с актом — акт и соглашение машины с созвона.
     *
     * @return Collection<int, Media>
     */
    public static function files(Offer $offer, Template $template): Collection
    {
        $files = $template->getMedia('files');
        if ($template->attach === Template::PICKUP_ACT && ($t = $offer->pickupTask) && $offer->car) {
            $from = $t->agreed_at ?? $t->created_at;
            $files = $files->concat($offer->car->getMedia('papers')->filter(fn (Media $m) => $m->created_at?->gte($from->copy()->subDay())
                && preg_match(self::ACT_FILE, (string) ($m->getCustomProperty('title') ?: $m->name ?: $m->file_name)) === 1));
        }

        return $files->filter(fn (Media $m) => is_file($m->getPath()))->values();
    }
}
