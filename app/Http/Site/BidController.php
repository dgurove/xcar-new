<?php

namespace App\Http\Site;

use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\WithdrawBid;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\Offer;
use Illuminate\Http\Request;

class BidController
{
    public function store(Request $request, Offer $offer, PlaceBid $place)
    {
        $kind = BidKind::tryFrom((string) $request->input('kind')) ?? BidKind::Buyer;
        $data = $request->validate([
            'amount' => [$kind === BidKind::Buyer ? 'required' : 'nullable', 'string'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $amount = (int) preg_replace('/\D+/', '', (string) ($data['amount'] ?? '')) ?: null;
        $place($offer, $request->user(), $amount, $data['comment'] ?? null, $kind);

        return back()->with('toast', $kind === BidKind::Garage ? 'В гараж — ждёт решения' : 'Подтверждение отправлено');
    }

    public function withdraw(Request $request, Bid $bid, WithdrawBid $withdraw)
    {
        abort_unless($bid->user_id === $request->user()->id, 403);
        $withdraw($bid, $request->user());

        return back()->with('toast', 'Подтверждение отозвано');
    }
}
