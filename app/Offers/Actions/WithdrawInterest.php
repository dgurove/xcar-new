<?php

namespace App\Offers\Actions;

use App\Offers\Events\InterestWithdrawn;
use App\Offers\Interest;
use App\Offers\OfferEventType;
use App\Users\User;

/** Покупатель снял интерес: строка уходит, в истории оффера остаётся след. */
final class WithdrawInterest
{
    public function __invoke(Interest $interest, User $by): void
    {
        $offer = $interest->offer;
        $interest->delete();
        $offer->log(OfferEventType::Interest, $by, ['withdrawn' => true]);
        InterestWithdrawn::dispatch($offer, $by);
    }
}
