<?php

namespace App\Billing;

use App\Billing\Actions\IssueInvoice;
use App\Billing\Documents\StorageActPdf;
use App\Park\City;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Закрытие месяца: кому и за что выставить хранение. Помесячные ТС — по конец месяца (или по день выдачи,
 * если выбыла раньше); ТС «после выдачи» — за весь период, когда выбыла; покупатель — свой отрезок отдельно.
 * Ничего не выставляет само: строки с галочками на экране, «Выставить выбранные» — `issue()`.
 *
 * @phpstan-type Item array{key: string, vehicle: Vehicle, payer: string, party: ?Party, ready: bool, cadence: Cadence, from: Carbon, to: Carbon, days: int, amount: float, charges: list<int>, reason: string}
 */
final class Closing
{
    /** @return Collection<int, Item> */
    public static function items(CarbonInterface $month): Collection
    {
        // Текущий месяц — по сегодня: будущие дни не выставляются.
        $end = min(Carbon::instance($month)->endOfMonth()->startOfDay(), now()->startOfDay());
        $begin = Carbon::instance($month)->startOfMonth()->startOfDay();
        $vehicles = Vehicle::whereIn('state', [VehicleState::Stored, VehicleState::InTransit, VehicleState::Released])->whereNotNull('accepted_at')
            ->where(fn ($q) => $q->whereNull('storage_billed_until')->orWhereColumn('storage_billed_until', '<', 'released_at')->orWhere(fn ($w) => $w->whereNull('released_at')->where('storage_billed_until', '<', $end)))
            ->with(['brand', 'model', 'vendor', 'yard', 'ownerParty', 'buyerParty', 'offer.deal.buyer',
                // Лента площадок — заранее: ставка считается по площадке того дня, иначе запрос на каждую ТС.
                'events' => fn ($e) => $e->whereIn('type', [EventType::Accepted, EventType::Moved, EventType::Departed])])->orderBy('accepted_at')->get();
        $items = collect();
        foreach ($vehicles as $v) {
            $cadence = $v->cadence();
            $released = $v->released_at?->copy()->startOfDay();
            if ($cadence === Cadence::Release) {
                // «По выбытию» — те, кто выбыл до конца этого месяца: выбывшие позже идут в свой месяц.
                if (! $released || $released->gt($end)) {
                    continue;
                }
                $until = $released;
                $reason = 'выбыла '.$released->translatedFormat('j M');
            } else {
                $until = $released && $released->lt($end) ? $released : $end;
                $reason = $released && $released->lt($end) ? 'выбыла '.$released->translatedFormat('j M') : 'за '.Carbon::instance($month)->translatedFormat('F');
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
            $pending = Charge::where('vehicle_id', $v->id)->whereNull('invoice_id')->whereNull('voided_at')->get();
            foreach ($groups as $n => $g) {
                $party = Ledger::payerParty($v, $g['payer'], false);
                // Начисления вне счёта того же контрагента — в его первый счёт.
                $charges = $party?->id ? $pending->where('party_id', $party->id) : collect();
                $pending = $pending->diff($charges);
                $items->push(['key' => $v->id.':'.$n, 'vehicle' => $v, 'payer' => $g['payer'], 'party' => $party, 'ready' => (bool) $party?->billable(), 'cadence' => $cadence, 'from' => $g['from'], 'to' => $g['to'], 'days' => $g['days'],
                    'amount' => round($g['amount'] + $charges->sum('amount'), 2), 'charges' => $charges->pluck('id')->values()->all(),
                    // Хранение по этой ТС не выставлялось ни разу: период идёт с приёма, и «за сентябрь» врало бы.
                    // Сам период стоит рядом отдельным чипом, поэтому здесь — только почему сумма такая.
                    'reason' => $cadence !== Cadence::Release && $g['from']->lt($begin) ? 'с приёма' : $reason]);
            }
        }

        return $items;
    }

    /**
     * Выставить выбранное: вендору и страхователю — один счёт-реестр на плательщика за месяц (решение владельца
     * 08.10.2026), машины строками, акт с реестром; покупателю — счёт на каждую ТС, как прежде. В порядке отрезков: чужой
     * отрезок раньше своего не даст выставить. Машина, которую выставить нельзя, не валит реестр — уходит в ошибки.
     *
     * @param  list<string>  $keys
     * @return array{issued: Collection<int, Invoice>, errors: list<string>}
     */
    public static function issue(CarbonInterface $month, array $keys, User $by, IssueInvoice $issue, StorageActPdf $act): array
    {
        $issued = collect();
        $errors = [];
        $title = fn (Vehicle $v) => $v->titleWithYear().($v->ref ? ' '.$v->ref : '');
        // В том же порядке, что отрезки у машины: вендор, страхователь, покупатель. Иначе счёт следующему плательщику
        // упёрся бы в ещё не выставленный отрезок предыдущего («сначала счёт ему»).
        $order = ['vendor' => 0, 'owner' => 1, 'buyer' => 2];
        $groups = self::items($month)->whereIn('key', $keys)->groupBy(fn ($i) => self::groupOf($i))->sortBy(fn ($g) => $order[$g->first()['payer']] ?? 3);
        foreach ($groups as $group) {
            $first = $group->first();
            $v = $first['vehicle'];
            try {
                $party = self::payer($first);
                $invoice = $first['payer'] === 'buyer'
                    ? $issue($party, $by, 'issued', ChargeKind::Storage, now(), $first['to'], $first['charges'], vehicle: $v->fresh())
                    : $issue($party, $by, 'issued', ChargeKind::Storage, now()->addWeekdays($v->vendor?->payment_days ?? 5), month: $month,
                        registry: $group->map(fn ($i) => ['vehicle' => $i['vehicle']->fresh(), 'until' => $i['to'], 'charges' => $i['charges']])->values()->all());
                $errors = [...$errors, ...$issue->skipped];
                $act->attach($invoice);
                $issued->push($invoice);
            } catch (Throwable $e) {
                $who = $first['payer'] === 'buyer' ? $title($v) : ($first['party']?->name ?? Accrual::payerLabel($first['payer']));
                $errors = [...$errors, $who.': '.self::message($e), ...$issue->skipped];
            }
        }

        return ['issued' => $issued, 'errors' => $errors];
    }

    /**
     * Сколько счетов ждёт закрытия прошлого месяца: реестр на плательщика и счёт на ТС покупателя — число у «Закрытия
     * месяца» и на вкладке «Оплаты». Считать дорого (начисления всех ТС) — в кэше, сбрасывают выставление и аннулирование.
     */
    public static function pendingCount(): int
    {
        $month = now()->subMonth()->startOfMonth();

        // Только то, что можно выставить: без плательщика и реквизитов число висело бы вечно.
        $city = City::viewing();

        return Cache::remember('park.closing:'.$month->format('Y-m').($city ? ":{$city}" : ''), 600, fn () => self::items($month)->where('ready', true)
            ->groupBy(fn ($i) => self::groupOf($i))->count());
    }

    /** Сбросить число «выставить» — после счёта или аннулирования парковки (CRM его не трогает). */
    public static function forgetPending(): void
    {
        $month = now()->subMonth()->format('Y-m');
        Cache::forget("park.closing:{$month}");
        foreach (array_keys(City::options()) as $city) {
            Cache::forget("park.closing:{$month}:{$city}");
        }
    }

    /**
     * Будущий счёт строки — одна группа на экране и при выставлении: реестр на плательщика (у вендора без записанного
     * контрагента — сам вендор, черновик заведётся один раз), покупателю — своя на каждую ТС.
     */
    public static function groupOf(array $item): string
    {
        if ($item['payer'] === 'buyer') {
            return 'buyer:'.$item['key'];
        }

        // Один счёт — один продавец: контрагент, общий у вендоров с разными продавцами (Альфа Москва — ИП, Альфа СПб —
        // ПРАЙМ), даёт по реестру на каждого.
        $seller = ':'.Seller::ofVendor($item['vehicle']->vendor)->value;

        return ($id = self::payer($item, false)?->id) ? 'p'.$id.$seller : ($item['payer'] === 'vendor' ? 'v'.$item['vehicle']->vendor_id : 'none:'.$item['key']);
    }

    /** Плательщик строки; черновик вендора (без id) записывается, когда выставляем. */
    private static function payer(array $item, bool $create = true): ?Party
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
