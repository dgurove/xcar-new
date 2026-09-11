<?php

namespace App\Offers\Actions;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\OfferEventType;
use App\Users\User;

final class WithdrawBid
{
    public function __invoke(Bid $bid, User $by): Bid
    {
        if ($bid->state === BidState::Active) {
            $bid->update(['state' => BidState::Withdrawn]);
            $bid->offer->log(OfferEventType::BidWithdrawn, $by, ['bid_id' => $bid->id]);
        }

        return $bid;
    }
}
