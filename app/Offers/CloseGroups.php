<?php

namespace App\Offers;

/**
 * Подпись пачки в каталоге по сроку (владелец 10.10.2026): слот закрывает приём у пачки предложений в один час —
 * «До 17:00 сегодня», «До 17:00 завтра», «До 17:00 13 октября»; закрытое и «Идёт сделка» — одной пачкой в конце.
 */
final class CloseGroups
{
    public static function label(Offer $offer): string
    {
        $at = $offer->bids_close_at;
        if (! $offer->bidsOpen() || $offer->state !== OfferState::Open) {
            return 'Приём закрыт';
        }
        if (! $at) {
            return 'Без срока';
        }
        $day = match (true) {
            $at->isToday() => 'сегодня',
            $at->isTomorrow() => 'завтра',
            default => $at->translatedFormat('j F'),
        };

        return 'До '.$at->format('H:i').' '.$day;
    }
}
