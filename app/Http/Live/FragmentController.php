<?php

namespace App\Http\Live;

use App\Live\Stream;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Support\Nav;
use Illuminate\Http\Request;

/** Фрагменты, которые клиент перечитывает по событию из хаба. */
class FragmentController
{
    public function badges(Request $request)
    {
        return Stream::view('live.badges-stream', ['badges' => Nav::badges($request->user())]);
    }

    public function card(Request $request, Offer $offer)
    {
        $offer->load(['brand', 'model', 'settlement', 'media', 'favorites']);
        Showing::remember($request->user(), [$offer->id]);
        // Карточка живёт в ленте, только если человеку положено её видеть: чужому — remove.
        $visible = ($request->query('list') === 'gallery' ? $offer->state === OfferState::Gallery : $offer->state->isPublic())
            && $offer->isVisibleTo($request->user());
        $present = $request->boolean('present');

        return Stream::view('site.offers.card-stream', ['offer' => $offer, 'visible' => $visible, 'present' => $present]);
    }
}
