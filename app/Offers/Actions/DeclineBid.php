<?php

namespace App\Offers\Actions;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Events\BidDeclined;
use App\Offers\OfferEventType;
use App\Users\User;

final class DeclineBid
{
    public function __invoke(Bid $bid, User $by): Bid
    {
        if ($bid->state === BidState::Active) {
            $bid->update(['state' => BidState::Declined, 'decided_at' => now(), 'decided_by' => $by->id]);
            $bid->offer->log(OfferEventType::BidDeclined, $by, ['bid_id' => $bid->id]);
            BidDeclined::dispatch($bid, $by);
        }

        return $bid;
    }
}
