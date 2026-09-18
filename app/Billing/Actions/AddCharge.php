<?php

namespace App\Billing\Actions;

use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Events\ChargeAdded;
use App\Billing\Party;
use App\Offers\Deal;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Support\Nav;
use App\Users\User;

/** Начисление руками или из события (эвакуация закрыта): без счёта, до выставления. */
final class AddCharge
{
    public function __invoke(Party $party, ChargeKind $kind, string $title, float $qty, string $unit, float $price, ?User $by = null, ?Vehicle $vehicle = null, ?Deal $deal = null, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): Charge
    {
        Nav::forgetStaffCounts();
        $charge = Charge::create([
            'party_id' => $party->id, 'vehicle_id' => $vehicle?->id, 'deal_id' => $deal?->id, 'kind' => $kind, 'title' => $title,
            'qty' => $qty, 'unit' => $unit, 'price' => $price, 'amount' => round($qty * $price, 2),
            'period_from' => $from, 'period_to' => $to, 'created_by' => $by?->id,
        ]);
        $vehicle?->log(EventType::Charged, $by, ['title' => $title, 'amount' => $charge->amount]);
        ChargeAdded::dispatch($charge, $by);

        return $charge;
    }
}
