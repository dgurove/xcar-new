<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;

final class CreateOffer
{
    public function __invoke(User $by, array $data = []): Offer
    {
        $offer = new Offer($data);
        $offer->moderator_id = $by->id;
        $offer->save();
        $offer->log(OfferEventType::Created, $by);

        return $offer->refresh();
    }
}
