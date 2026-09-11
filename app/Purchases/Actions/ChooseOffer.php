<?php

namespace App\Purchases\Actions;

use App\Purchases\Offer;
use App\Purchases\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Выбрать цену за машину: остальные живые по ней — «не выбрана». Повторный выбор перекладывает. */
final class ChooseOffer
{
    public function __invoke(Offer $offer, User $by): Offer
    {
        return DB::transaction(function () use ($offer) {
            Offer::where('car_id', $offer->car_id)->whereIn('state', [OfferState::Active, OfferState::Chosen])->where('id', '!=', $offer->id)->update(['state' => OfferState::Declined]);
            $offer->update(['state' => OfferState::Chosen]);
            $offer->user->notify(new \App\Notifications\PurchaseOfferChosenNotice($offer->load('car.purchase')));

            return $offer;
        });
    }
}
