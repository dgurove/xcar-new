<?php

namespace App\Http\Live;

use App\Live\Stream;
use App\Offers\CatalogQuery;
use App\Offers\Offer;
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
        // Та же выборка, что у каталога: «новое» от открытия этому человеку, «Ваша цена», свой интерес.
        $offer = CatalogQuery::cards($request->user(), $offer->isGallery())->whereKey($offer->id)->first() ?? $offer;
        Showing::remember($request->user(), [$offer->id]);
        // Карточка живёт в ленте, только если человеку положено её видеть: чужому — remove.
        $visible = ($request->query('list') === 'gallery' ? $offer->isGallery() : $offer->state->isPublic())
            && $offer->isVisibleTo($request->user());
        $present = $request->boolean('present');

        return Stream::view('site.offers.card-stream', ['offer' => $offer, 'visible' => $visible, 'present' => $present]);
    }
}
