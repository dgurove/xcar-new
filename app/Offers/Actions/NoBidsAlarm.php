<?php

namespace App\Offers\Actions;

use App\Notifications\NoBidsNotice;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Ни одного подтверждения (22.10.2026, владелец: «по горящему ни одного подтверждения — потеряли деньги»). Часы
 * (`offers:tick`): за 3 часа до конца приёма у открытого без живых подтверждений — тем, кто машину открывал (не из
 * избранного: тем своё «в избранном» в тот же час, `RemindUnreadClosing`), из его регионов; один раз на срок
 * (`no_bids_alerted_for`). «Уведомить менеджеров» из CRM (`notify`) — всем, кому она открыта, из их регионов.
 */
final class NoBidsAlarm
{
    public const HOURS = 3;

    public function __invoke(): int
    {
        $n = 0;
        foreach (self::burning()->where('offers.bids_close_at', '<=', now()->addHours(self::HOURS))
            ->where(fn ($q) => $q->whereNull('no_bids_alerted_for')->orWhereColumn('no_bids_alerted_for', '!=', 'bids_close_at'))
            ->lazyById() as $offer) {
            DB::table('offers')->where('id', $offer->id)->update(['no_bids_alerted_for' => $offer->bids_close_at]);
            $n += $this->send($offer, readers: true);
        }

        return $n;
    }

    /** «Уведомить менеджеров» (CRM): всем, кому машина открыта, кроме подтвердивших. Возвращает, скольким ушло. */
    public function notify(Offer $offer): int
    {
        return $this->send($offer, readers: false);
    }

    /** Открытое, приём идёт, ни одного живого подтверждения — «горит без подтверждений» (и чип CRM). */
    public static function burning(): Builder
    {
        return Offer::where('offers.state', OfferState::Open)->where('offers.is_demo', false)->where('offers.bids_close_at', '>', now())
            ->whereDoesntHave('bids', fn ($b) => $b->whereIn('state', [BidState::Active, BidState::Accepted]));
    }

    private function send(Offer $offer, bool $readers): int
    {
        $users = $this->audience($offer, $readers)->filter(fn (User $u) => $offer->regionOpenTo($u) && $offer->isVisibleTo($u));
        Notification::send($users, new NoBidsNotice($offer));

        return $users->count();
    }

    /** @return Collection<int, User> */
    private function audience(Offer $offer, bool $readers): Collection
    {
        $ids = DB::table('offer_viewers as v')->where('v.offer_id', $offer->id)->where('v.opens_at', '<=', now())
            ->when($readers, fn ($q) => $q
                ->whereExists(fn ($r) => $r->selectRaw('1')->from('offer_reads as r')->whereColumn('r.offer_id', 'v.offer_id')->whereColumn('r.user_id', 'v.user_id'))
                ->whereNotExists(fn ($f) => $f->selectRaw('1')->from('favorites as f')->whereColumn('f.offer_id', 'v.offer_id')->whereColumn('f.user_id', 'v.user_id')))
            ->pluck('v.user_id');

        return User::whereIn('id', $ids)->withRole(Role::Manager, Role::SuperBuyer)->whereNull('rejected_at')->get();
    }
}
