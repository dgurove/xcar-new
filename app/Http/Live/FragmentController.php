<?php

namespace App\Http\Live;

use App\Live\Stream;
use App\Offers\Offer;
use App\Support\Nav;
use Illuminate\Http\Request;

/** Фрагменты, которые клиент перечитывает по событию из хаба. */
class FragmentController
{
    public function badges(Request $request)
    {
        $surface = \App\Http\Middleware\ParkHost::isPark($request) ? 'park' : 'site';

        return Stream::view('live.badges-stream', ['badges' => Nav::badges($request->user(), $surface), 'surface' => $surface]);
    }

    public function card(Request $request, Offer $offer)
    {
        $offer->load(['brand', 'model', 'settlement', 'media', 'favorites']);
        $visible = $request->query('list') === 'gallery' ? $offer->state === \App\Offers\OfferState::Gallery : $offer->state->isPublic();
        $present = $request->boolean('present');

        return Stream::view('site.offers.card-stream', ['offer' => $offer, 'visible' => $visible, 'present' => $present]);
    }
}
