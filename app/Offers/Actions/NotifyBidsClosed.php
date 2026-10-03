<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\BidsClosed;
use App\Telegram\Messages\SlotClosing;
use Illuminate\Support\Facades\DB;

/**
 * Раз в минуту: у открытых предложений, чей срок приёма прошёл, — сообщение владельцу, один раз на срок.
 * Продлили срок — он снова не совпадает с отмеченным, и после нового закрытия сообщение уйдёт заново.
 * Вышедшие слотом — иначе (владелец 03.10.2026): за час до срока одно сообщение на весь слот (`SlotClosing`), и в
 * сам срок по ним уже ничего, отметка та же. Продлили срок — после нового закрытия обычное «Приём закрыт».
 */
final class NotifyBidsClosed
{
    /** Слоты, закрывающиеся в ближайший час: одно сообщение на срок. */
    public function closing(): int
    {
        $offers = Offer::where('state', OfferState::Open)->whereNotNull('slot_at')
            ->where('bids_close_at', '>', now())->where('bids_close_at', '<=', now()->addHour())
            ->where(fn ($q) => $q->whereNull('bids_closed_notified_for')->orWhereColumn('bids_closed_notified_for', '!=', 'bids_close_at'))
            ->with('bids')->orderBy('id')->get();
        foreach ($offers->groupBy(fn (Offer $o) => $o->bids_close_at->toDateTimeString()) as $group) {
            DB::table('offers')->whereIn('id', $group->pluck('id'))->update(['bids_closed_notified_for' => DB::raw('bids_close_at')]);
            NotifyOwner::dispatch(new SlotClosing($group->values()));
        }

        return $offers->count();
    }

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
