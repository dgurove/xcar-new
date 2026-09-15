<?php

namespace App\Offers;

use App\Users\User;

/**
 * Где на телефоне живёт блок цены: в шторке, которую открывает полоса
 * действий, или в потоке. Одно правило для блока и для строки цены над
 * характеристиками — иначе цена то дублируется, то пропадает.
 */
final class DealPlacement
{
    public static function inSheet(Offer $offer, ?User $user, ?Interest $myInterest): bool
    {
        if (! $user) {
            return true;
        }
        $prices = PriceView::for($offer, $user)->visible;

        return ($user->role->canBid() && $offer->bidsOpen() && $prices)
            || ($user->role->canInterest() && $offer->state->acceptsInterest() && ! $myInterest);
    }
}
