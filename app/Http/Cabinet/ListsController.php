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
        // Избранное — только то, что человеку и сейчас видно: закрытое менеджером покупателю не показываем.
        $offers = Offer::with(['brand', 'model', 'media', 'favorites'])
            ->whereHas('favorites', fn ($f) => $f->where('user_id', $request->user()->id))
            ->when($request->user()->isBuyer(), fn ($q) => $q->visibleTo($request->user())->with(['interests' => fn ($i) => $i->where('user_id', $request->user()->id)]))
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
        $user = $request->user();
        $interests = Interest::with(['offer.brand', 'offer.model', 'offer.media'])->where('user_id', $user->id)->latest()->paginate(30);
        // Что из отмеченного всё ещё открыто человеку: проданное и закрытое остаётся в списке с пометкой, но без перехода.
        $live = Offer::whereIn('id', $interests->pluck('offer_id'))->visibleTo($user)->pluck('id')->all();

        return view('cabinet.interests', ['interests' => $interests, 'live' => $live, 'manager' => $user->isBuyer() ? $user->manager : null]);
    }
}
