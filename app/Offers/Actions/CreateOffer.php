<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;

final class CreateOffer
{
    /** @param array $log пометка в ленте о происхождении (например, закупка и ДЛ) */
    public function __invoke(User $by, array $data = [], array $log = []): Offer
    {
        $offer = new Offer($data);
        $offer->moderator_id = $by->id;
        $offer->save();
        $offer->log(OfferEventType::Created, $by, $log);

        return $offer->refresh();
    }
}
