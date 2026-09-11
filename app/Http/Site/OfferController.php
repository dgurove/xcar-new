<?php

namespace App\Http\Site;

use App\Offers\BidState;
use App\Offers\Offer;
use Illuminate\Http\Request;

class OfferController
{
    public function show(Request $request, Offer $offer)
    {
        $user = $request->user();
        abort_unless($offer->state->isPublic() || $offer->state->acceptsInterest() || $user?->isStaff(), 404);

        $offer->load(['brand', 'model', 'settlement', 'media', 'favorites']);

        return view('site.offers.show', [
            'offer' => $offer,
            'photos' => $offer->visiblePhotos(),
            'myBid' => $user ? $offer->bids()->where('user_id', $user->id)->where('state', BidState::Active)->first() : null,
            'myInterest' => $user ? $offer->interests()->where('user_id', $user->id)->first() : null,
        ]);
    }
}
