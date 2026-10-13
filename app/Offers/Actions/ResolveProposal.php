<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;

/**
 * Решение по черновику «Предложено из заявок на приём» (`Park\Actions\AutoSale`, плашка `x-offer.proposal`):
 * «Принять» — плашка уходит, черновик остаётся в своей вкладке; «Отклонить» — черновик удаляется, ТС остаётся на парковке
 * со всеми файлами (`UnlistParkOffer`), `AutoSale` её больше не выставляет (событие `Unlinked` в истории ТС).
 */
final class ResolveProposal
{
    public function __construct(private UnlistParkOffer $unlist) {}

    public function accept(Offer $offer, User $by): void
    {
        if (! $offer->proposed()) {
            return;
        }
        $offer->forceFill(['proposed_at' => null])->save();
        $offer->log(OfferEventType::Proposal, $by, ['accepted' => true]);
    }

    public function decline(Offer $offer, User $by): void
    {
        ($this->unlist)($offer, $by);
    }

    /** «Отклонить» рисуется, только когда удаление пройдёт (не публиковали, нет сделки и подтверждений). */
    public static function declinable(Offer $offer): bool
    {
        return $offer->proposed() && UnlistParkOffer::allowed($offer);
    }
}
