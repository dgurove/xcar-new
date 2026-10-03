<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;

/** «Убрать из слота»: остаётся черновиком (галереей), часы его не выпустят. */
final class UnscheduleOffer
{
    public function __invoke(Offer $offer, User $by): Offer
    {
        if (! $offer->isScheduled()) {
            return $offer;
        }
        $offer->forceFill(['slot_at' => null, 'slot_by' => null])->save();
        $offer->log(OfferEventType::Scheduled, $by);

        return $offer;
    }
}
