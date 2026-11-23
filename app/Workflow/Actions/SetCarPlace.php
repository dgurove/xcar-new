<?php

namespace App\Workflow\Actions;

use App\Mail\Actions\ReadSettled;
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
        $was = $offer->car_place;
        $offer->update(['car_place' => $place]);
        $offer->log(OfferEventType::PlaceChanged, $by, ['place' => $place?->value]);
        // Забрали у владельца — письма о вывозе до этого отработаны (`ReadSettled`).
        app(ReadSettled::class)->onPlace($offer, $was, $place);

        return $offer;
    }
}
