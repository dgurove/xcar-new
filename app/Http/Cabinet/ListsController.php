<?php

namespace App\Http\Cabinet;

use App\Offers\Bid;
use App\Offers\Interest;
use App\Offers\Offer;
use Illuminate\Http\Request;

class ListsController
{
    public function favorites(Request $request)
    {
        $offers = Offer::with(['brand', 'model', 'media', 'favorites'])
            ->whereHas('favorites', fn ($f) => $f->where('user_id', $request->user()->id))
            ->latest()->paginate(24);

        return view('cabinet.favorites', ['offers' => $offers]);
    }

    public function bids(Request $request)
    {
        $bids = Bid::with(['offer.brand', 'offer.model', 'offer.media'])->where('user_id', $request->user()->id)->latest()->paginate(30);

        return view('cabinet.bids', ['bids' => $bids]);
    }

    public function interests(Request $request)
    {
        $interests = Interest::with(['offer.brand', 'offer.model', 'offer.media'])->where('user_id', $request->user()->id)->latest()->paginate(30);

        return view('cabinet.interests', ['interests' => $interests]);
    }
}
