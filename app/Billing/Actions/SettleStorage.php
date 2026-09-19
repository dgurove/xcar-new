<?php

namespace App\Billing\Actions;

use App\Billing\Accrual;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\Ledger;
use App\Billing\PaymentSource;
use App\Park\Vehicle;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Хранение по день выдачи — счетами по плательщикам в порядке отрезков: вендору (или страхователю) за его дни,
 * покупателю за его. Счёт покупателю при `cash` тут же гасится наличными — ТС уезжает, деньги в кассе.
 * Нужен, когда ТС выдают с невыставленным хранением: закрытие месяца сюда уже не успеет.
 *
 * @return Collection<int, Invoice>
 */
final class SettleStorage
{
    public function __construct(private IssueInvoice $issue, private RecordPayment $pay) {}

    public function __invoke(Vehicle $vehicle, User $by, CarbonInterface $until, bool $cash = false): Collection
    {
        $vehicle->loadMissing('vendor');
        $invoices = collect();
        $segments = Accrual::storage($vehicle, $until)->filter(fn ($s) => $s['amount'] > 0);
        // Отрезки одного плательщика подряд — один счёт по последний его день.
        $groups = [];
        foreach ($segments as $s) {
            $last = $groups ? array_key_last($groups) : null;
            if ($last !== null && $groups[$last]['payer'] === $s['payer']) {
                $groups[$last]['to'] = $s['to'];
                $groups[$last]['amount'] += $s['amount'];
            } else {
                $groups[] = ['payer' => $s['payer'], 'to' => $s['to'], 'amount' => $s['amount']];
            }
        }
        foreach ($groups as $g) {
            $party = Ledger::payerParty($vehicle, $g['payer']);
            if (! $party) {
                throw ValidationException::withMessages(['state' => 'Некому выставить хранение за '.Accrual::payerLabel($g['payer']).' — заполните покупателя или комитента']);
            }
            $buyer = $g['payer'] === 'buyer';
            $invoice = ($this->issue)($party, $by, 'issued', ChargeKind::Storage, $buyer ? now() : now()->addWeekdays($vehicle->vendor?->payment_days ?? 5),
                $buyer ? false : (bool) ($vehicle->vendor?->vat_included ?? false), $g['to'], vehicle: $vehicle);
            if ($buyer && $cash) {
                ($this->pay)($invoice, $by, (float) $invoice->total, now(), PaymentSource::Cash);
            }
            $invoices->push($invoice->fresh());
            $vehicle->refresh();
        }

        return $invoices;
    }
}
