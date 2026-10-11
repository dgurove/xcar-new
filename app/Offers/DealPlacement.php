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

        // Черновик на оценке у этого менеджера (11.10.2026): «Оценить» снизу открывает лист с суммой.
        if ($offer->state === OfferState::Draft && $user->isManager() && Estimate::openFor($offer, $user)) {
            return true;
        }

        return ($user->bidsOnSite() && ($offer->bidsOpen() || $offer->canBidInDeal($user)) && $prices)
            || ($user->canInterestIn($offer) && ! $myInterest);
    }
}
