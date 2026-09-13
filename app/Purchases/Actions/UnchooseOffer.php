<?php

namespace App\Purchases\Actions;

use App\Notifications\PurchaseOfferUnchosenNotice;
use App\Purchases\Offer;
use App\Purchases\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Отменить выбор: цена снова ждёт, и все «не выбранные» по этой машине тоже — их отклонил только выбор. */
final class UnchooseOffer
{
    public function __invoke(Offer $offer, User $by): Offer
    {
        return DB::transaction(function () use ($offer) {
            Offer::where('car_id', $offer->car_id)->whereIn('state', [OfferState::Chosen, OfferState::Declined])->update(['state' => OfferState::Active]);
            $offer->user->notify(new PurchaseOfferUnchosenNotice($offer->refresh()->load('car.purchase')));

            return $offer;
        });
    }
}
