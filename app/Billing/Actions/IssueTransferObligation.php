<?php

namespace App\Billing\Actions;

use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\WorkDays;
use App\Park\Vehicle;
use App\Users\User;

/**
 * Договор комиссии: с приёма по акту мы должны вендору назначенную цену в N рабочих дней — обязательство `owed`.
 * Вписали договор позже приёма — обязательство появляется тогда; изменили цену, пока не перечислено, —
 * пересчитывается; договор сняли — неоплаченное аннулируется.
 */
final class IssueTransferObligation
{
    public function __construct(private IssueInvoice $issue, private VoidInvoice $void) {}

    public function __invoke(Vehicle $vehicle, User $by): ?Invoice
    {
        $vehicle->loadMissing('vendor');
        $open = Invoice::where('vehicle_id', $vehicle->id)->where('direction', 'owed')->where('state', InvoiceState::Issued)->first();
        $wanted = $vehicle->contract_kind === 'commission' && $vehicle->assigned_price && $vehicle->vendor;
        if (! $wanted) {
            if ($open && $open->paid <= 0) {
                ($this->void)($open, $by, 'договор комиссии снят');
            }

            return null;
        }
        if ($open) {
            if ($open->paid <= 0 && abs($open->total - $vehicle->assigned_price) > 0.005) {
                Charge::where('invoice_id', $open->id)->where('kind', ChargeKind::Transfer)->update(['price' => $vehicle->assigned_price, 'amount' => $vehicle->assigned_price]);
                $open->update(['total' => round((float) $vehicle->assigned_price, 2), 'external_no' => $vehicle->contract_no]);
            }

            return $open;
        }
        if (Invoice::where('vehicle_id', $vehicle->id)->where('direction', 'owed')->where('state', InvoiceState::Paid)->exists()) {
            return null;
        }
        $days = $vehicle->vendor->payment_days ?? 3;

        return ($this->issue)(Party::forVendor($vehicle->vendor), $by, 'owed', ChargeKind::Transfer, WorkDays::add($vehicle->accepted_at ?? now(), $days), false,
            lines: [['title' => 'Оплата за ТС '.$vehicle->titleWithYear().($vehicle->contract_no ? ' по договору комиссии № '.$vehicle->contract_no : ''), 'qty' => 1, 'unit' => 'pc', 'price' => $vehicle->assigned_price, 'kind' => 'transfer']],
            vehicle: $vehicle, offerId: $vehicle->offer_id, externalNo: $vehicle->contract_no);
    }
}
