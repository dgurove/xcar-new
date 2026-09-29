<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\BidsClosed;
use Illuminate\Support\Facades\DB;

/**
 * Раз в минуту: у открытых предложений, чей срок приёма прошёл, — сообщение владельцу, один раз на срок.
 * Продлили срок — он снова не совпадает с отмеченным, и после нового закрытия сообщение уйдёт заново.
 */
final class NotifyBidsClosed
{
    public function __invoke(): int
    {
        $sent = 0;
        foreach (Offer::where('state', OfferState::Open)->where('bids_close_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('bids_closed_notified_for')->orWhereColumn('bids_closed_notified_for', '!=', 'bids_close_at'))
            ->lazyById() as $offer) {
            DB::table('offers')->where('id', $offer->id)->update(['bids_closed_notified_for' => $offer->bids_close_at]);
            NotifyOwner::dispatch(new BidsClosed($offer));
            $sent++;
        }

        return $sent;
    }
}
