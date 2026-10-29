<?php

namespace App\Purchases\Actions;

use App\Notifications\PurchaseOfferChosenNotice;
use App\Offers\OfferEventType;
use App\Purchases\Offer;
use App\Purchases\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Выбрать цену за машину: остальные живые по ней — «не выбрана». Повторный выбор перекладывает. */
final class ChooseOffer
{
    public function __invoke(Offer $offer, User $by): Offer
    {
        return DB::transaction(function () use ($offer, $by) {
            $others = Offer::where('car_id', $offer->car_id)->whereIn('state', [OfferState::Active, OfferState::Chosen])->where('id', '!=', $offer->id)->get();
            Offer::whereKey($others->modelKeys())->update(['state' => OfferState::Declined]);
            $offer->update(['state' => OfferState::Chosen]);
            $offer->car->log(OfferEventType::BidAccepted, $by, ['amount' => $offer->amount, 'manager' => $offer->user_id]);
            foreach ($others as $other) {
                $offer->car->log(OfferEventType::BidDeclined, $by, ['amount' => $other->amount, 'manager' => $other->user_id]);
            }
            $offer->user->notify(new PurchaseOfferChosenNotice($offer->load('car.purchase')));

            return $offer;
        });
    }
}
