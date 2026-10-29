<?php

namespace App\Purchases\Actions;

use App\Notifications\PurchaseOfferChosenNotice;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\OfferEventType;
use App\Purchases\Car;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Выбрать цену за машину: остальные живые по ней — «не выбрана». Повторный выбор перекладывает. Только нынешнюю цену
 * человека: сменённую или отозванную, пока у админа висела старая страница, не выбрать.
 */
final class ChooseOffer
{
    public function __invoke(Bid $bid, User $by): Bid
    {
        return DB::transaction(function () use ($bid, $by) {
            $car = Car::whereKey($bid->purchase_car_id)->lockForUpdate()->firstOrFail();
            $bid->refresh();
            $newer = $car->bids()->where('user_id', $bid->user_id)->where('id', '>', $bid->id)->exists();
            if ($newer || ! in_array($bid->state, [BidState::Active, BidState::Declined, BidState::Accepted], true)) {
                throw ValidationException::withMessages(['offer' => 'Цену уже сменили или отозвали — обновите страницу']);
            }
            if ($bid->state === BidState::Accepted) {
                return $bid;
            }
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
