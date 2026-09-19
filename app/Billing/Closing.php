<?php

namespace App\Billing;

use App\Billing\Actions\IssueInvoice;
use App\Billing\Documents\StorageActPdf;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Закрытие месяца: кому и за что выставить хранение. Помесячные ТС — по конец месяца (или по день выдачи,
 * если выбыла раньше); ТС «после выдачи» — за весь период, когда выбыла; покупатель — свой отрезок отдельно.
 * Ничего не выставляет само: строки с галочками на экране, «Выставить выбранные» — `issue()`.
 *
 * @phpstan-type Item array{key: string, vehicle: Vehicle, payer: string, party: ?Party, cadence: Cadence, from: Carbon, to: Carbon, days: int, amount: float, charges: list<int>, reason: string}
 */
final class Closing
{
    /** @return Collection<int, Item> */
    public static function items(CarbonInterface $month): Collection
    {
        // Текущий месяц — по сегодня: будущие дни не выставляются.
        $end = min(Carbon::instance($month)->endOfMonth()->startOfDay(), now()->startOfDay());
        $vehicles = Vehicle::whereIn('state', [VehicleState::Stored, VehicleState::InTransit, VehicleState::Released])->whereNotNull('accepted_at')
            ->where(fn ($q) => $q->whereNull('storage_billed_until')->orWhereColumn('storage_billed_until', '<', 'released_at')->orWhere(fn ($w) => $w->whereNull('released_at')->where('storage_billed_until', '<', $end)))
            ->with(['brand', 'model', 'vendor', 'yard', 'ownerParty', 'buyerParty', 'offer.deal.buyer'])->orderBy('accepted_at')->get();
        $items = collect();
        foreach ($vehicles as $v) {
            $cadence = $v->cadence();
            $released = $v->released_at?->copy()->startOfDay();
            if ($cadence === Cadence::Release) {
                if (! $released || $released->gt(now())) {
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
                $items->push(['key' => $v->id.':'.$n, 'vehicle' => $v, 'payer' => $g['payer'], 'party' => $party, 'cadence' => $cadence, 'from' => $g['from'], 'to' => $g['to'], 'days' => $g['days'],
                    'amount' => round($g['amount'] + $charges->sum('amount'), 2), 'charges' => $charges->pluck('id')->values()->all(), 'reason' => $reason]);
            }
        }

        return $items;
    }

    /**
     * Выставить выбранные строки: в порядке отрезков (чужой отрезок раньше своего не даст выставить), к каждому счёту — акт PDF.
     * Ошибка одной строки не валит остальные — возвращается рядом с выставленными.
     *
     * @param  list<string>  $keys
     * @return array{issued: Collection<int, Invoice>, errors: list<string>}
     */
    public static function issue(CarbonInterface $month, array $keys, User $by, IssueInvoice $issue, StorageActPdf $act): array
    {
        $issued = collect();
        $errors = [];
        foreach (self::items($month)->whereIn('key', $keys) as $item) {
            $v = $item['vehicle'];
            try {
                $party = $item['party'] ?? Ledger::payerParty($v, $item['payer']);
                if (! $party) {
                    throw new \RuntimeException('некому выставить — '.Accrual::payerLabel($item['payer']));
                }
                if (! $party->exists) {
                    $party = Ledger::payerParty($v, $item['payer']);
                }
                $buyer = $item['payer'] === 'buyer';
                $invoice = $issue($party, $by, 'issued', ChargeKind::Storage, $buyer ? now() : now()->addWeekdays($v->vendor?->payment_days ?? 5),
                    $buyer ? false : (bool) ($v->vendor?->vat_included ?? false), $item['to'], $item['charges'], vehicle: $v->fresh());
                $act->attach($invoice);
                $issued->push($invoice);
            } catch (Throwable $e) {
                $errors[] = $v->titleWithYear().($v->ref ? ' '.$v->ref : '').': '.($e instanceof ValidationException ? implode(', ', $e->validator->errors()->all()) : $e->getMessage());
            }
        }

        return ['issued' => $issued, 'errors' => $errors];
    }
}
