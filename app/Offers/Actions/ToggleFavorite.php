<?php

namespace App\Offers\Actions;

use App\Offers\Favorite;
use App\Offers\Offer;
use App\Users\User;

final class ToggleFavorite
{
    /** @return bool теперь в избранном */
    public function __invoke(Offer $offer, User $by): bool
    {
        $existing = Favorite::where(['user_id' => $by->id, 'offer_id' => $offer->id])->first();
        if ($existing) {
            $existing->delete();

            return false;
        }
        Favorite::create(['user_id' => $by->id, 'offer_id' => $offer->id]);

        return true;
    }
}
