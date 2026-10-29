<?php

namespace App\Purchases\Actions;

use App\Notifications\PurchaseOfferUnchosenNotice;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Отменить выбор: цена снова ждёт, и все «не выбранные» по этой машине тоже — их отклонил только выбор. */
final class UnchooseOffer
{
    public function __invoke(Bid $bid, User $by): Bid
    {
        return DB::transaction(function () use ($bid, $by) {
            $car = $bid->purchaseCar;
            $car->log(OfferEventType::Note, $by, ['unchosen' => true, 'amount' => $bid->amount, 'manager' => $bid->user_id]);
            $car->bids()->whereIn('state', [BidState::Accepted, BidState::Declined])->update(['state' => BidState::Active, 'decided_at' => null, 'decided_by' => null]);
            $bid->user->notify(new PurchaseOfferUnchosenNotice($bid->refresh()->load('purchaseCar.purchase')));

            return $bid;
        });
    }
}
