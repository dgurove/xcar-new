<?php

namespace App\Purchases\Actions;

use App\Notifications\PurchaseOfferUnchosenNotice;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\OfferEventType;
use App\Purchases\Car;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Отменить выбор: цена снова ждёт, и «не выбранные» по этой машине тоже — их отклонил только выбор. Живая у человека одна
 * (`bids_one_active_lot`): назвал после выбора новую — она и остаётся, «не выбранная» уходит в заменённые; из нескольких
 * «не выбранных» оживает последняя.
 */
final class UnchooseOffer
{
    public function __invoke(Bid $bid, User $by): Bid
    {
        return DB::transaction(function () use ($bid, $by) {
            $car = Car::whereKey($bid->purchase_car_id)->lockForUpdate()->firstOrFail();
            $bid->refresh();
            if ($bid->state !== BidState::Accepted) {
                return $bid;
            }
            $car->log(OfferEventType::Note, $by, ['unchosen' => true, 'amount' => $bid->amount, 'manager' => $bid->user_id]);
            $bid->update(['state' => BidState::Active, 'decided_at' => null, 'decided_by' => null]);

            $live = $car->bids()->where('state', BidState::Active)->pluck('user_id')->all();
            foreach ($car->bids()->where('state', BidState::Declined)->reorder()->orderByDesc('id')->get()->groupBy('user_id') as $user => $mine) {
                $last = in_array($user, $live) ? null : $mine->shift();
                $last?->update(['state' => BidState::Active, 'decided_at' => null, 'decided_by' => null]);
                $car->bids()->whereKey($mine->modelKeys())->update(['state' => BidState::Replaced]);
            }
            $bid->user->notify(new PurchaseOfferUnchosenNotice($bid->refresh()->load('purchaseCar.purchase')));

            return $bid;
        });
    }
}
