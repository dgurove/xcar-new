<?php

namespace App\Offers\Actions;

use App\Notifications\UnreadClosingNotice;
use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Напоминание до срока (21.10.2026, владелец: «менеджеры не видят, когда приходят новые машины, проёбываемся по срокам»):
 * за 3 часа до конца приёма менеджеру и супер-покупателю — одно сообщение о машинах, которые он не открывал и не
 * подтверждал, одно на человека и момент закрытия (у слота у всех 17:00 — обычно одно в день). Срочные не в счёт — о них
 * уже громко; кому машина открылась уже внутри этих трёх часов, тому только что пришло «Опубликовано N». Отметка
 * `offer_viewers.closing_reminded_at` — до отправки, снимается новой публикацией (`Notify::offerPublished`). Шаг `offers:tick`.
 */
final class RemindUnreadClosing
{
    public const HOURS = 3;

    public function __invoke(): int
    {
        $now = now();
        $rows = collect(DB::select('update offer_viewers v set closing_reminded_at = ?
            from offers o, users u
            where o.id = v.offer_id and u.id = v.user_id and v.closing_reminded_at is null
              and o.state = ? and o.is_demo = false and o.urgent_at is null
              and o.bids_close_at > ? and o.bids_close_at <= ?
              and v.opens_at <= o.bids_close_at - make_interval(hours => ?)
              and jsonb_exists_any(u.roles, array[?, ?]) and u.rejected_at is null
              and not exists (select 1 from offer_reads r where r.user_id = v.user_id and r.offer_id = v.offer_id and r.read_at >= o.published_at)
              and not exists (select 1 from bids b where b.offer_id = v.offer_id and b.user_id = v.user_id)
            returning v.user_id, v.offer_id', [
            $now, OfferState::Open->value, $now, $now->copy()->addHours(self::HOURS), self::HOURS, Role::Manager->value, Role::SuperBuyer->value,
        ]));
        $sent = 0;
        foreach ($rows->groupBy('user_id') as $userId => $mine) {
            $user = User::find($userId);
            if (! $user) {
                continue;
            }
            // Видно ему и в продаже сейчас (сделка, снятие) — тем же правилом, что каталог.
            $offers = CatalogQuery::unread(Offer::visibleTo($user)->onSale()->whereIn('offers.id', $mine->pluck('offer_id')), $user)
                ->orderBy('offers.bids_close_at')->orderByDesc('offers.published_at')->get();
            foreach ($offers->groupBy(fn (Offer $o) => $o->bids_close_at->format('Y-m-d H:i')) as $at => $pack) {
                $user->notify(new UnreadClosingNotice($pack->count(), NotifyNewOffers::brands($pack), Carbon::parse($at), $pack->count() === 1 ? $pack->first() : null));
                $sent++;
            }
        }

        return $sent;
    }
}
