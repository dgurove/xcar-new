<?php

namespace App\Workflow\Actions;

use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;

/** «Где машина» на оффере — одной дверью с записью в ленту; зовут этап маршрута и стоянка. */
final class SetCarPlace
{
    public function __invoke(Offer $offer, ?CarPlace $place, ?User $by = null): Offer
    {
        if ($offer->car_place === $place) {
            return $offer;
        }
        $offer->update(['car_place' => $place]);
        $offer->log(OfferEventType::PlaceChanged, $by, ['place' => $place?->value]);

        return $offer;
    }
}
