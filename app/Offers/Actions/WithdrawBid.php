<?php

namespace App\Offers\Actions;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Validation\ValidationException;

final class WithdrawBid
{
    public function __invoke(Bid $bid, User $by): Bid
    {
        // После срока приёма подтверждения — в работе у нас: отозвать уже нельзя.
        if ($bid->state === BidState::Active && ! $bid->offer->bidsOpen()) {
            throw ValidationException::withMessages(['bid' => 'Приём закрыт, подтверждение уже нельзя отозвать']);
        }
        if ($bid->state === BidState::Active) {
            $bid->update(['state' => BidState::Withdrawn]);
            $bid->offer->log(OfferEventType::BidWithdrawn, $by, ['bid_id' => $bid->id]);
        }

        return $bid;
    }
}
