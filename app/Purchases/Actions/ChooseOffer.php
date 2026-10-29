<?php

namespace App\Purchases\Actions;

use App\Notifications\PurchaseOfferChosenNotice;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Выбрать цену за машину: остальные живые по ней — «не выбрана». Повторный выбор перекладывает. */
final class ChooseOffer
{
    public function __invoke(Bid $bid, User $by): Bid
    {
        return DB::transaction(function () use ($bid, $by) {
            $car = $bid->purchaseCar;
            $others = $car->bids()->whereIn('state', [BidState::Active, BidState::Accepted])->whereKeyNot($bid->id)->get();
            $car->bids()->whereKey($others->modelKeys())->update(['state' => BidState::Declined, 'decided_at' => now(), 'decided_by' => $by->id]);
            $bid->update(['state' => BidState::Accepted, 'decided_at' => now(), 'decided_by' => $by->id]);
            $car->log(OfferEventType::BidAccepted, $by, ['amount' => $bid->amount, 'manager' => $bid->user_id]);
            foreach ($others as $other) {
                $car->log(OfferEventType::BidDeclined, $by, ['amount' => $other->amount, 'manager' => $other->user_id]);
            }
            $bid->user->notify(new PurchaseOfferChosenNotice($bid->load('purchaseCar.purchase')));

            return $bid;
        });
    }
}
