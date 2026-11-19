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
    public static function inSheet(Offer $offer, ?User $user, ?Interest $myInterest, ?Bid $myBid = null): bool
    {
        if (! $user) {
            return true;
        }
        // Контрпредложение ждёт ответа (и после конца приёма) — «Контрпредложение N» снизу открывает лист с «Согласен» /
        // «Отказаться» (19.11.2026: блок стоял на странице, а кнопка плашки слала `deal:open` в пустоту — два места, одно мёртвое).
        if ($myBid?->hasPendingCounter()) {
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
