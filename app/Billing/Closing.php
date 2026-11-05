<?php

namespace App\Billing;

use App\Billing\Actions\IssueInvoice;
use App\Park\City;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Ago;
use App\Vendors\Tariff;
use App\Vendors\Vendor;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Счета и акты за месяц (05.11.2026, владелец: «не должно быть понятия „Выставить“ — они должны быть актуальными всегда,
 * просто зафиксировать, если мы их отправили»): документ плательщика за месяц живой — `draft()` считает его заново из
 * данных (ставки, даты приёма и выдачи), ничего не записывая; отправка письмом или «Отправили сами» фиксирует его —
 * `Actions\FixClosingDocument`: номер, строки, `storage_billed_until`, файлы, `sent_at`. Исправили данные после отправки — `drift()` видит
 * разницу, `FixClosingDocument` с `replaces` отправляет исправленный с тем же номером и следующей редакцией, прежний аннулирован.
 *
 * Что входит — строго свой месяц: помесячные ТС — в месяце первого невыставленного дня, по конец месяца (или по день
 * выдачи); «по выбытию» — выбывшие в этом месяце, за весь срок; покупатель — свой отрезок своим документом; эвакуация —
 * своим документом плательщику в месяц своей даты (`tows`).
 *
 * @phpstan-type Item array{key: string, vehicle: Vehicle, payer: string, party: ?Party, ready: bool, cadence: Cadence, from: Carbon, to: Carbon, days: int, amount: float, charges: list<int>, reason: string, tow: bool}
 */
final class Closing
{
    private const CACHE = 'park.closing.v4:';

    /**
     * Строки месяца — что ещё не зафиксировано. `$without` — как если бы этого счёта не было (сверка и исправление: его
     * ТС считаются с первого дня его строк, его начисления — свободными; в базе ничего не меняется); `$only` — только эти ТС.
     *
     * @param  ?list<int>  $only
     * @return Collection<int, Item>
     */
    public static function items(CarbonInterface $month, ?Invoice $without = null, ?array $only = null): Collection
    {
        // Текущий месяц — по сегодня: будущие дни не считаются.
        $end = min(Carbon::instance($month)->endOfMonth()->startOfDay(), now()->startOfDay());
        $begin = Carbon::instance($month)->startOfMonth()->startOfDay();
        // С какого дня ТС этого счёта снова «не выставлены» — с первого дня её строк в нём, как сделает `VoidInvoice`.
        $replaced = $without ? $without->charges()->where('kind', ChargeKind::Storage)->whereNotNull('vehicle_id')->orderBy('period_from')->get()->groupBy('vehicle_id') : collect();
        $vehicles = Vehicle::whereIn('state', [VehicleState::Stored, VehicleState::Released])->whereNotNull('accepted_at')
            ->where(fn ($q) => $q->whereNull('storage_billed_until')->orWhereColumn('storage_billed_until', '<', 'released_at')->orWhere(fn ($w) => $w->whereNull('released_at')->where('storage_billed_until', '<', $end))
                ->when($replaced->isNotEmpty(), fn ($x) => $x->orWhereIn('id', $replaced->keys())))
            ->when($only !== null, fn ($q) => $q->whereIn('id', $only))
            ->with(['vendor.party', 'vendor.contacts', 'yard', 'ownerParty', 'buyerParty', 'offer.deal.buyer',
                // Лента площадок — заранее: ставка считается по площадке того дня, иначе запрос на каждую ТС.
                'events' => fn ($e) => $e->whereIn('type', [EventType::Accepted, EventType::Moved, EventType::Departed])])->orderBy('accepted_at')->get();
        foreach ($vehicles as $v) {
            if ($rows = $replaced->get($v->id)) {
                // Только в памяти: модель дальше идёт в `Accrual`, а не в базу.
                $v->setAttribute('storage_billed_until', self::billedBefore($rows, $v));
            }
        }
        // Начисления вне счёта — одной выборкой на всех: запрос на ТС давал 411 запросов на экран (аудит 20.10.2026).
        // Эвакуация — своим документом (архив «СТОЯНКА», 05.11.2026: у СОГАЗа свой договор, у Альфы свой счёт) — `tows()`.
        // Начисления позже месяца (октябрьские при сентябрьском документе) ждут своего месяца.
        $pendingBy = self::free($without)->whereIn('vehicle_id', $vehicles->modelKeys())->where('kind', '!=', ChargeKind::Tow->value)
            ->whereRaw('coalesce(period_from, created_at::date) <= ?', [$end->toDateString()])->get()->groupBy('vehicle_id');
        $items = collect();
        foreach ($vehicles as $v) {
            $cadence = $v->cadence();
            $released = $v->released_at?->copy()->startOfDay();
            // Документ месяца — строго свой месяц (05.11.2026, владелец: «в счёте за октябрь строки, выданные в сентябре, —
            // тупо рандом»): неотправленный сентябрь не переезжает в октябрь.
            if ($cadence === Cadence::Release) {
                // «По выбытию» — только выбывшие в этом месяце, за весь невыставленный срок.
                if (! $released || $released->lt($begin) || $released->gt($end)) {
                    continue;
                }
                $until = $released;
                $reason = 'выбыла '.Ago::day($released);
            } else {
                // Помесячно — только в месяце первого невыставленного дня: дни сентября — в сентябре, октябрь — после него.
                $first = $v->storage_billed_until ? $v->storage_billed_until->copy()->addDay()->startOfDay() : $v->accepted_at->copy()->startOfDay();
                if ($first->lt($begin) || $first->gt($end)) {
                    continue;
                }
                $until = $released && $released->lt($end) ? $released : $end;
                $reason = $released && $released->lt($end) ? 'выбыла '.Ago::day($released) : 'за '.Carbon::instance($month)->translatedFormat('F');
            }
            $groups = [];
            foreach (Accrual::storage($v, $until)->filter(fn ($s) => $s['amount'] > 0) as $s) {
                $last = $groups ? array_key_last($groups) : null;
                if ($last !== null && $groups[$last]['payer'] === $s['payer']) {
                    $groups[$last]['to'] = $s['to'];
                    $groups[$last]['days'] += $s['days'];
                    $groups[$last]['amount'] += $s['amount'];
                } else {
                    $groups[] = ['payer' => $s['payer'], 'from' => $s['from'], 'to' => $s['to'], 'days' => $s['days'], 'amount' => $s['amount']];
                }
            }
            $pending = $pendingBy->get($v->id, collect());
            foreach ($groups as $n => $g) {
                $party = Ledger::payerParty($v, $g['payer'], false);
                // Начисления вне счёта того же контрагента — в его первый документ.
                $charges = $party?->id ? $pending->where('party_id', $party->id) : collect();
                $pending = $pending->diff($charges);
                $items->push(['key' => $v->id.':'.$n, 'vehicle' => $v, 'payer' => $g['payer'], 'party' => $party, 'ready' => self::ready($party), 'cadence' => $cadence, 'from' => $g['from'], 'to' => $g['to'], 'days' => $g['days'],
                    'amount' => round($g['amount'] + $charges->sum('amount'), 2), 'charges' => $charges->pluck('id')->values()->all(),
                    // Хранение по этой ТС не выставлялось ни разу: период идёт с приёма, и «за сентябрь» врало бы.
                    // Сам период стоит рядом отдельным чипом, поэтому здесь — только почему сумма такая.
                    'reason' => $cadence !== Cadence::Release && $g['from']->lt($begin) ? 'с приёма' : $reason, 'tow' => false]);
            }
        }

        return $items->concat(self::tows($end, $without, $only));
    }

    /**
     * По какой день ТС выставлена без этих строк хранения (их — по возрастанию начала): с приёма — «не выставлялось»,
     * иначе день до первой. Одна дверь для аннулирования и сверки.
     *
     * @param  Collection<int, Charge>  $rows
     */
    public static function billedBefore(Collection $rows, Vehicle $vehicle): ?Carbon
    {
        $first = $rows->first();
        $day = $first->period_from->copy()->subDay();

        return $first->starts_billing || ($vehicle->accepted_at && $day->lt($vehicle->accepted_at->copy()->startOfDay())) ? null : $day;
    }

    /** Начисления, свободные от счёта; у `$without` — и те, что он освободит при аннулировании (начисленные до него). */
    private static function free(?Invoice $without)
    {
        return Charge::whereNull('voided_at')->where(fn ($q) => $q->whereNull('invoice_id')
            ->when($without, fn ($x) => $x->orWhere(fn ($w) => $w->where('invoice_id', $without->id)->where('kind', '!=', ChargeKind::Storage->value)->where('created_at', '<', $without->created_at))));
    }

    /**
     * Эвакуация этого месяца — строкой на ТС и плательщика: в документ эвакуации, а не хранения, в месяц, когда она
     * была (не «всё до конца месяца»: неотправленная сентябрьская в октябрь не переезжает), как бы ни считалось хранение.
     *
     * @param  ?list<int>  $only
     * @return Collection<int, Item>
     */
    private static function tows(CarbonInterface $end, ?Invoice $without = null, ?array $only = null): Collection
    {
        $charges = self::free($without)->whereNotNull('vehicle_id')->where('kind', ChargeKind::Tow->value)
            ->whereRaw('coalesce(period_from, created_at::date) between ? and ?', [Carbon::instance($end)->startOfMonth()->toDateString(), $end->toDateString()])
            ->when($only !== null, fn ($q) => $q->whereIn('vehicle_id', $only))
            ->whereHas('vehicle', fn ($v) => $v->whereNotNull('accepted_at'))
            ->with(['vehicle.vendor.party', 'vehicle.ownerParty', 'vehicle.buyerParty', 'party'])->orderBy('id')->get();

        return $charges->groupBy(fn ($c) => $c->vehicle_id.':'.$c->party_id)->map(function ($rows, $key) {
            $v = $rows->first()->vehicle;
            $party = $rows->first()->party;
            $payer = match (true) {
                $party && $party->id === $v->vendor?->party_id => 'vendor',
                $party && $party->id === $v->owner_party_id => 'owner',
                default => 'buyer',
            };
            $day = fn ($c) => Carbon::parse($c->period_from ?? $c->created_at)->startOfDay();

            return ['key' => 'tow:'.$key, 'vehicle' => $v, 'payer' => $payer, 'party' => $party, 'ready' => self::ready($party), 'cadence' => $v->cadence(),
                'from' => $rows->map($day)->min(), 'to' => $rows->map($day)->max(), 'days' => 0, 'amount' => round((float) $rows->sum('amount'), 2),
                'charges' => $rows->pluck('id')->values()->all(), 'reason' => 'эвакуация', 'tow' => true];
        })->values();
    }

    /**
     * Живой документ группы — счёт, не записанный в базу: строки хранения той же дверью, что запишет отправка
     * (`IssueInvoice::storageLines`), начисления, итог, номер, который он получит сейчас. Нечего — null.
     */
    public static function draft(CarbonInterface $month, string $group, ?Invoice $replaces = null): ?Invoice
    {
        $rows = self::rowsOf($month, $group, $replaces);
        if ($rows->isEmpty()) {
            return null;
        }
        $first = $rows->first();
        $v = $first['vehicle'];
        $party = self::payer($first, false);
        if (! $party) {
            return null; // некому: покупатель без контрагента — документ не из чего собрать
        }
        $registry = $first['tow'] || $first['payer'] !== 'buyer';
        $seller = $registry ? Seller::ofVendor($v->vendor) : Seller::for($v);
        $vatRate = $seller->vatRate() ?: null;
        $charges = collect();
        $skipped = [];
        foreach ($rows as $i) {
            if (! $i['tow']) {
                try {
                    $charges = $charges->concat(IssueInvoice::storageLines($i['vehicle'], $party, $i['to']));
                } catch (ValidationException $e) {
                    $skipped[] = $i['vehicle']->titleWithYear().': '.self::message($e);

                    continue;
                }
            }
            $charges = $charges->concat(Charge::whereIn('id', $i['charges'])->get()->each->setRelation('vehicle', $i['vehicle']));
        }
        $total = round((float) $charges->sum('amount'), 2);
        $onTop = $vatRate && $party->vat_on_top;
        $invoice = new Invoice([
            'seller' => $seller, 'park' => true, 'direction' => 'issued', 'year' => (int) now()->format('Y'),
            'number' => $replaces?->number ?? Numbering::next($seller, (int) now()->format('Y')),
            'code' => $replaces?->code ?? Numbering::code($seller, now()), 'revision' => $replaces ? $replaces->revision + 1 : 0,
            'kind' => $first['tow'] ? ChargeKind::Tow : ChargeKind::Storage, 'month' => $registry ? Carbon::instance($month)->startOfMonth()->toDateString() : null,
            'party_id' => $party->id, 'vehicle_id' => $registry ? null : $v->id, 'issued_at' => now()->toDateString(),
            'due_at' => ($registry ? now()->addWeekdays($v->vendor?->payment_days ?? 5) : now())->toDateString(),
            'vat' => (bool) $vatRate, 'vat_rate' => $vatRate, 'vat_on_top' => $onTop, 'total' => $onTop ? round($total * (1 + $vatRate / 100), 2) : $total, 'paid' => 0,
        ]);
        $invoice->setRelation('party', $party)->setRelation('charges', $charges->values())->setRelation('vehicle', $registry ? null : $v)
            ->setRelation('payments', collect())->setRelation('deal', null)->setRelation('media', collect())
            // Не вошедшие ТС с причиной — отношением, а не свойством: модель черновика не записывается.
            ->setRelation('skipped', collect($skipped));

        return $invoice;
    }

    /**
     * Данные отправленного реестра хранения изменились (дата приёма или выдачи, ставка, тип ТС): сколько было и сколько
     * сейчас по тем же ТС. Без изменений — null. Считается в памяти, в кэше на 10 минут до правки ТС или счёта.
     *
     * @return ?array{was: float, now: float}
     */
    public static function drift(Invoice $invoice): ?array
    {
        if (! $invoice->isRegistry() || $invoice->kind !== ChargeKind::Storage || $invoice->state === InvoiceState::Void || ! ($group = self::groupOfInvoice($invoice))) {
            return null;
        }
        $ids = $invoice->registryVehicles()->pluck('id')->all();
        // Ключ — всё, от чего зависит сумма: ТС (даты, тип, площадка), прайс, вендор (порядок счёта, плательщик), контрагент;
        // иначе исправленную цену сверка не видела бы до конца кэша.
        $vendors = Vehicle::whereIn('id', $ids)->distinct()->pluck('vendor_id')->filter()->all();
        $stamp = implode(':', [
            Vehicle::whereIn('id', $ids)->max('updated_at'),
            Tariff::where(fn ($q) => $q->whereIn('vendor_id', $vendors)->orWhereNull('vendor_id'))->max('updated_at'),
            Vendor::whereIn('id', $vendors)->max('updated_at'),
            $invoice->party?->updated_at?->timestamp,
        ]);
        $key = self::CACHE.'drift:'.$invoice->id.':'.$invoice->updated_at?->timestamp.':'.md5($stamp);

        return Cache::remember($key, 600, function () use ($invoice, $group, $ids) {
            $draft = self::draft($invoice->month, $group, $invoice);
            $now = $draft ? (float) $draft->total : 0.0;
            $same = $draft && $draft->charges->pluck('vehicle_id')->unique()->sort()->values()->all() === collect($ids)->sort()->values()->all();

            return abs($now - (float) $invoice->total) < 0.01 && $same ? null : ['was' => (float) $invoice->total, 'now' => $now];
        });
    }

    /** Группа зафиксированного реестра — та же, что у живого документа (`groupOf`): по ней его исправляют. */
    public static function groupOfInvoice(Invoice $invoice): ?string
    {
        if (! $invoice->isRegistry()) {
            return null;
        }
        $key = 'p'.$invoice->party_id.':'.$invoice->seller->value;

        return $invoice->kind === ChargeKind::Tow ? 'tow:'.$key : $key;
    }

    /**
     * Строки группы; у исправления — только ТС исправляемого счёта.
     *
     * @param  ?list<int>  $only
     * @return Collection<int, Item>
     */
    public static function rowsOf(CarbonInterface $month, string $group, ?Invoice $replaces = null, ?array $only = null): Collection
    {
        $only ??= $replaces?->registryVehicles()->pluck('id')->all();

        return self::items($month, $replaces, $only)->filter(fn ($i) => self::groupOf($i) === $group)->values();
    }

    /**
     * Сколько счетов ждёт закрытия прошлого месяца: реестр на плательщика и счёт на ТС покупателя — число у «Закрытия
     * месяца» и на вкладке «Оплаты». Считать дорого (начисления всех ТС) — в кэше, сбрасывают выставление и аннулирование.
     */
    public static function pendingCount(): int
    {
        return self::pending()['count'];
    }

    /**
     * Что ждёт закрытия прошлого месяца — строкой сверху «Оплат» (Питер, 09.10.2026: без неё управляющий про
     * сентябрь не узнал бы): месяц, сколько ТС и на какую сумму, сколько счетов можно выставить (`count`) и скольким
     * вендорам сначала нужны реквизиты (`blocked`). Вендор без реквизитов — дело управляющего, он в числе; покупатель
     * без контрагента — нет: такое число висело бы вечно.
     *
     * @return array{month: string, count: int, cars: int, amount: float, blocked: int}
     */
    public static function pending(): array
    {
        $month = now()->subMonth()->startOfMonth();
        $city = City::viewing();

        // «v2» — с 09.10.2026 в кэше сводка массивом, а не число: прежнее значение под старым ключом не читается.
        return Cache::remember(self::CACHE.$month->format('Y-m').($city ? ":{$city}" : ''), 600, function () use ($month) {
            $items = self::items($month);
            $groups = $items->groupBy(fn ($i) => self::groupOf($i));
            $blocked = $groups->filter(fn ($g) => ! $g->first()['ready'] && $g->first()['payer'] === 'vendor')->count();

            return ['month' => $month->toDateString(), 'count' => $groups->filter(fn ($g) => $g->first()['ready'])->count() + $blocked,
                'cars' => $items->pluck('vehicle.id')->unique()->count(), 'amount' => round((float) $items->sum('amount'), 2), 'blocked' => $blocked];
        });
    }

    /** Сбросить число «выставить» — после счёта или аннулирования парковки (CRM его не трогает). */
    public static function forgetPending(): void
    {
        $month = now()->subMonth()->format('Y-m');
        Cache::forget(self::CACHE.$month);
        foreach (array_keys(City::options()) as $city) {
            Cache::forget(self::CACHE."{$month}:{$city}");
        }
    }

    /**
     * Будущий счёт строки — одна группа на экране и при выставлении: реестр на плательщика (у вендора без записанного
     * контрагента — сам вендор, черновик заведётся один раз), покупателю — своя на каждую ТС.
     */
    public static function groupOf(array $item): string
    {
        if ($item['tow'] ?? false) {
            // Эвакуация — один счёт плательщику за месяц, у любого плательщика.
            return 'tow:'.($item['party']?->id ? 'p'.$item['party']->id.':'.Seller::ofVendor($item['vehicle']->vendor)->value : 'none:'.$item['key']);
        }
        if ($item['payer'] === 'buyer') {
            return 'buyer:'.$item['key'];
        }

        // Один счёт — один продавец: контрагент, общий у вендоров с разными продавцами (Альфа Москва — ИП, Альфа СПб —
        // ПРАЙМ), даёт по реестру на каждого.
        $seller = ':'.Seller::ofVendor($item['vehicle']->vendor)->value;

        return ($id = self::payer($item, false)?->id) ? 'p'.$id.$seller : ($item['payer'] === 'vendor' ? 'v'.$item['vehicle']->vendor_id : 'none:'.$item['key']);
    }

    /**
     * Плательщику можно выставить: есть чем назвать (ИНН, у человека — паспорт или карта). Его банк в счёте не печатается —
     * платит он нам (05.11.2026: у Альфы банка нет ни в одном прежнем счёте, а с ним закрытие стояло бы у 170 ТС).
     */
    private static function ready(?Party $party): bool
    {
        return (bool) $party?->filled();
    }

    /** Плательщик строки; черновик вендора (без id) записывается, когда выставляем. */
    public static function payer(array $item, bool $create = true): ?Party
    {
        $party = $item['party'] ?? Ledger::payerParty($item['vehicle'], $item['payer'], $create);
        if ($party && ! $party->exists && $create) {
            $party = Ledger::payerParty($item['vehicle'], $item['payer']);
        }
        if (! $party && $create) {
            throw new \RuntimeException('некому выставить, '.mb_strtolower(Accrual::payerLabel($item['payer'])));
        }

        return $party;
    }

    private static function message(Throwable $e): string
    {
        return $e instanceof ValidationException ? implode(', ', $e->validator->errors()->all()) : $e->getMessage();
    }
}
