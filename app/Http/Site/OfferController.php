<?php

namespace App\Http\Site;

use App\Chats\Chat;
use App\Media\Actions\WarmPhotos;
use App\Offers\BidState;
use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\ListContext;
use Illuminate\Http\Request;

class OfferController
{
    public function show(Request $request, Offer $offer)
    {
        $user = $request->user();
        abort_unless($offer->state->isPublic() || $offer->state->acceptsInterest() || $user?->isStaff(), 404);

        $offer->load(['brand', 'model', 'settlement', 'media', 'favorites']);
        if (in_array($offer->state, [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered], true)) {
            app(WarmPhotos::class)($offer); // холодный слой: конверсии досчитаются в очереди
        }

        // Откуда пришли: стрелки листают ровно тот список, что человек видел.
        $context = ListContext::fromRequest($request);
        $position = $context
            ? CatalogQuery::position($user, $context->filters, $context->isGallery(), $offer)
            : ['prev' => null, 'next' => null, 'index' => null, 'total' => 0];

        return view('site.offers.show', [
            'offer' => $offer,
            'photos' => $offer->visiblePhotos(),
            'context' => $context,
            'position' => $position,
            'myBid' => $user ? $offer->bids()->where('user_id', $user->id)->where('state', BidState::Active)->first() : null,
            'myInterest' => $user ? $offer->interests()->where('user_id', $user->id)->first() : null,
            'chat' => $user && ! $user->isStaff() ? Chat::where('offer_id', $offer->id)->where('user_id', $user->id)->first() : null,
        ]);
    }
}
