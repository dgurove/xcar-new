<?php

namespace App\Park\Actions;

use App\Offers\Offer;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;

/** ТС стоянки ↔ предложение CRM: по номеру убытка или VIN при одном совпадении, либо руками. */
final class LinkOffer
{
    public function __invoke(Vehicle $vehicle, ?Offer $offer = null, ?User $by = null): ?Offer
    {
        if ($vehicle->offer_id && ! $offer) {
            return $vehicle->offer;
        }
        $offer ??= self::guess($vehicle);
        if (! $offer || Vehicle::where('offer_id', $offer->id)->where('id', '!=', $vehicle->id)->exists()) {
            return null;
        }
        $vehicle->update(['offer_id' => $offer->id]);
        $vehicle->log(EventType::Linked, $by, ['number' => $offer->number]);

        return $offer;
    }

    /** Обратный поиск: ТС стоянки для оффера по номеру убытка или VIN. */
    public static function guessVehicle(Offer $offer): ?Vehicle
    {
        foreach ([['ref_key', $offer->claim_ref_key], ['vin', $offer->vin]] as [$column, $value]) {
            if (! $value) {
                continue;
            }
            $found = Vehicle::where($column, $value)->whereNull('offer_id')->where('state', '!=', VehicleState::Cancelled)->limit(2)->get();
            if ($found->count() === 1) {
                return $found->first();
            }
        }

        return null;
    }

    public static function guess(Vehicle $vehicle): ?Offer
    {
        foreach ([['claim_ref_key', $vehicle->ref_key], ['vin', $vehicle->vin]] as [$column, $value]) {
            if (! $value) {
                continue;
            }
            $found = Offer::where($column, $value)->whereDoesntHave('parkVehicle')->limit(2)->get();
            if ($found->count() === 1) {
                return $found->first();
            }
        }

        return null;
    }
}
