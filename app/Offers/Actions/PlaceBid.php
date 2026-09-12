<?php

namespace App\Offers\Actions;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Events\BidPlaced;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlaceBid
{
    public function __invoke(Offer $offer, User $by, int $amount, ?string $comment = null): Bid
    {
        if (! $by->role->canBid()) {
            throw ValidationException::withMessages(['amount' => 'Подтверждать могут только менеджеры']);
        }

        return DB::transaction(function () use ($offer, $by, $amount, $comment) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if (! $offer->bidsOpen()) {
                throw ValidationException::withMessages(['amount' => 'Приём подтверждений закрыт']);
            }
            $min = $offer->minBid();
            if ($min && $amount < $min) {
                throw ValidationException::withMessages(['amount' => 'Не меньше '.number_format($min, 0, '', ' ').' ₽']);
            }

            $offer->bids()->where('user_id', $by->id)->where('state', BidState::Active)->update(['state' => BidState::Withdrawn]);

            $bid = $offer->bids()->create(['user_id' => $by->id, 'amount' => $amount, 'comment' => $comment, 'state' => BidState::Active]);
            $offer->log(OfferEventType::BidPlaced, $by, ['bid_id' => $bid->id, 'amount' => $amount]);
            BidPlaced::dispatch($bid, $by);

            return $bid;
        });
    }
}
