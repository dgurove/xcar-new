<?php

namespace App\Billing\Actions;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\WorkDays;
use App\Park\Vehicle;
use App\Users\User;

/** Договор комиссии: с приёма по акту мы должны вендору назначенную цену в N рабочих дней — обязательство `owed`. */
final class IssueTransferObligation
{
    public function __construct(private IssueInvoice $issue) {}

    public function __invoke(Vehicle $vehicle, User $by): ?Invoice
    {
        $vehicle->loadMissing('vendor');
        if ($vehicle->contract_kind !== 'commission' || ! $vehicle->assigned_price || ! $vehicle->vendor) {
            return null;
        }
        if (Invoice::where('vehicle_id', $vehicle->id)->where('direction', 'owed')->where('state', '!=', InvoiceState::Void)->exists()) {
            return null;
        }
        $days = $vehicle->vendor->payment_days ?? 3;

        return ($this->issue)(Party::forVendor($vehicle->vendor), $by, 'owed', ChargeKind::Transfer, WorkDays::add($vehicle->accepted_at ?? now(), $days), false,
            lines: [['title' => 'Оплата за ТС '.$vehicle->titleWithYear().($vehicle->contract_no ? ' по договору комиссии № '.$vehicle->contract_no : ''), 'qty' => 1, 'unit' => 'pc', 'price' => $vehicle->assigned_price, 'kind' => 'transfer']],
            vehicle: $vehicle, offerId: $vehicle->offer_id, externalNo: $vehicle->contract_no);
    }
}
