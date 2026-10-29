<?php

namespace App\Purchases\Actions;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\OfferEventType;
use App\Purchases\Car;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Отозвать свою живую цену; под замком лота — выбранную в ту же секунду не отзовёт. */
final class WithdrawOffer
{
    public function __invoke(Bid $bid, User $by): void
    {
        DB::transaction(function () use ($bid, $by) {
            Car::whereKey($bid->purchase_car_id)->lockForUpdate()->first();
            $bid->refresh();
            if ($bid->user_id !== $by->id || $bid->state !== BidState::Active) {
                throw ValidationException::withMessages(['offer' => 'Отозвать нечего']);
            }
            $bid->update(['state' => BidState::Withdrawn]);
            $bid->purchaseCar->log(OfferEventType::BidWithdrawn, $by, ['amount' => $bid->amount]);
        });
    }
}
