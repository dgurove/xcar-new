<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;

final class UpdateOffer
{
    public function __invoke(Offer $offer, array $data, User $by): Offer
    {
        if (array_key_exists('vin', $data)) {
            $data['vin'] = $data['vin'] ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['vin'])) : null;
        }
        $offer->fill($data);
        $changed = array_keys($offer->getDirty());
        $offer->save();
        if ($changed) {
            $offer->log(OfferEventType::Updated, $by, ['fields' => $changed]);
        }

        return $offer;
    }
}
