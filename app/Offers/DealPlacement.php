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
        $gallery = $offer->isGallery();
        $prices = ! $gallery && $user->role->canSeePrices();

        return ($user->role->canBid() && $offer->bidsOpen() && $prices)
            || (! $user->isStaff() && ($gallery || ! $prices) && $offer->state->acceptsInterest() && ! $myInterest);
    }
}
