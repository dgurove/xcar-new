<?php

namespace App\Offers\Actions;

use App\Offers\Favorite;
use App\Offers\Offer;
use App\Support\Nav;
use App\Users\User;

final class ToggleFavorite
{
    /** @return bool теперь в избранном */
    public function __invoke(Offer $offer, User $by): bool
    {
        $existing = Favorite::where(['user_id' => $by->id, 'offer_id' => $offer->id])->first();
        $existing ? $existing->delete() : Favorite::create(['user_id' => $by->id, 'offer_id' => $offer->id]);
        // Число «Избранного» в шапке кэшируется на полминуты — следующая страница не должна показать прежнее.
        Nav::forget($by);

        return ! $existing;
    }
}
