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
 * Ни одного подтверждения (22.10.2026, владелец: «по горящему ни одного подтверждения — потеряли деньги»). Часами — не
 * отдельным уведомлением на машину, а строкой в напоминании за 3 часа (`RemindUnreadClosing`, 07.11.2026: менеджеру шло по
 * два в день рядом с «Приём до 17:00», открывали 9 %). Здесь — «горит без подтверждений» (чип CRM, плитка «Сгорает!») и
 * «Уведомить менеджеров» из CRM (`notify`) — всем, кому машина открыта, из их регионов, громко.
 */
final class NoBidsAlarm
{
    public const HOURS = 3;

    /** «Уведомить менеджеров» (CRM): всем, кому машина открыта, кроме подтвердивших. Возвращает, скольким ушло. */
    public function notify(Offer $offer): int
    {
        $users = $this->audience($offer)->filter(fn (User $u) => $offer->regionOpenTo($u) && $offer->isVisibleTo($u));
        Notification::send($users, new NoBidsNotice($offer));

        return $users->count();
    }

    /** Открытое, приём идёт, ни одного живого подтверждения — «горит без подтверждений» (и чип CRM). */
    public static function burning(): Builder
    {
        return Offer::where('offers.state', OfferState::Open)->where('offers.is_demo', false)->where('offers.bids_close_at', '>', now())
            ->whereDoesntHave('bids', fn ($b) => $b->whereIn('state', [BidState::Active, BidState::Accepted]));
    }

    /** @return Collection<int, User> */
    private function audience(Offer $offer): Collection
    {
        $ids = DB::table('offer_viewers as v')->where('v.offer_id', $offer->id)->where('v.opens_at', '<=', now())->pluck('v.user_id');

        return User::whereIn('id', $ids)->withRole(Role::Manager, Role::SuperBuyer)->whereNull('rejected_at')->get();
    }
}
