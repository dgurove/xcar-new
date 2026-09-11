<?php

namespace App\Http\Site;

use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\WithdrawBid;
use App\Offers\Bid;
use App\Offers\Offer;
use Illuminate\Http\Request;

class BidController
{
    public function store(Request $request, Offer $offer, PlaceBid $place)
    {
        $data = $request->validate([
            'amount' => ['required', 'string'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $amount = (int) preg_replace('/\D+/', '', $data['amount']);
        $place($offer, $request->user(), $amount, $data['comment'] ?? null);

        return back()->with('toast', 'Ставка принята к рассмотрению');
    }

    public function withdraw(Request $request, Bid $bid, WithdrawBid $withdraw)
    {
        abort_unless($bid->user_id === $request->user()->id, 403);
        $withdraw($bid, $request->user());

        return back()->with('toast', 'Ставка отозвана');
    }
}
