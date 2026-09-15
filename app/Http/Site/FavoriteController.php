<?php

namespace App\Http\Site;

use App\Offers\Actions\ToggleFavorite;
use App\Offers\Offer;
use Illuminate\Http\Request;

class FavoriteController
{
    public function toggle(Request $request, Offer $offer, ToggleFavorite $toggle)
    {
        abort_unless($offer->isVisibleTo($request->user()), 404);
        $on = $toggle($offer, $request->user());

        return response()->view('components.offer.favorite-stream', ['offer' => $offer->refresh()->load('favorites'), 'on' => $on])
            ->header('Content-Type', 'text/vnd.turbo-stream.html');
    }
}
