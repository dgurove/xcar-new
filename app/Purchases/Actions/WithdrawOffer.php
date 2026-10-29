<?php

namespace App\Purchases\Actions;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Validation\ValidationException;

final class WithdrawOffer
{
    public function __invoke(Bid $bid, User $by): void
    {
        if ($bid->user_id !== $by->id || $bid->state !== BidState::Active) {
            throw ValidationException::withMessages(['offer' => 'Отозвать нечего']);
        }
        $bid->update(['state' => BidState::Withdrawn]);
        $bid->purchaseCar->log(OfferEventType::BidWithdrawn, $by, ['amount' => $bid->amount]);
    }
}
