<?php

namespace App\Offers\Actions;

use App\Notifications\FavoriteClosingNotice;
use App\Notifications\UnreadClosingNotice;
use App\Offers\BidState;
use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Напоминание до срока (21.10.2026, владелец: «менеджеры не видят, когда приходят новые машины, проёбываемся по срокам»):
 * менеджеру и супер-покупателю, у кого нет своего живого подтверждения.
 * - За 3 часа — одно сообщение на человека и момент закрытия (у слота у всех 17:00 — обычно одно в день): машины в его
 *   «Избранном» (открывал или нет) и те, что он не открывал. Непросмотренное — если машина открылась ему раньше этих трёх
 *   часов: кому внутри них, тому только что пришло «Опубликовано N». Отметка `offer_viewers.closing_reminded_at`.
 * - За 30 минут — только «Избранное», громко (`last`, как «скоро закончится» у eBay). Отметка `last_reminded_at`.
 * Срочные не в счёт — о них уже громко. Отметки — до отправки, снимает новая публикация (`Notify::offerPublished`).
 * Шаги `offers:tick`.
 */
final class RemindUnreadClosing
{
    public const HOURS = 3;

    public const LAST_MINUTES = 30;

    /** Своё подтверждение, с которым напоминать не о чем. */
    private const LIVE_BID = 'exists (select 1 from bids b where b.offer_id = v.offer_id and b.user_id = v.user_id and b.state in (?, ?))';

    private const FAVORITE = 'exists (select 1 from favorites f where f.offer_id = v.offer_id and f.user_id = v.user_id)';

    public function __invoke(): int
    {
        $now = now();
        $rows = collect(DB::select('update offer_viewers v set closing_reminded_at = ?
            from offers o, users u
            where o.id = v.offer_id and u.id = v.user_id and v.closing_reminded_at is null and v.opens_at <= ?
              and o.state = ? and o.is_demo = false and o.urgent_at is null
              and o.bids_close_at > ? and o.bids_close_at <= ?
              and jsonb_exists_any(u.roles, array[?, ?]) and u.rejected_at is null
              and not '.self::LIVE_BID.'
              and ('.self::FAVORITE.' or (v.opens_at <= o.bids_close_at - make_interval(hours => ?)
                and not exists (select 1 from offer_reads r where r.user_id = v.user_id and r.offer_id = v.offer_id and r.read_at >= o.published_at)))
            returning v.user_id, v.offer_id, '.self::FAVORITE.' as favorite', [
            $now, $now, OfferState::Open->value, $now, $now->copy()->addHours(self::HOURS), Role::Manager->value, Role::SuperBuyer->value,
            BidState::Active->value, BidState::Accepted->value, self::HOURS,
        ]));
        $sent = 0;
        foreach ($rows->groupBy('user_id') as $userId => $mine) {
            $user = User::find($userId);
            if (! $user) {
                continue;
            }
            $favorites = $mine->filter(fn ($r) => $r->favorite)->pluck('offer_id')->all();
            // Видно ему и в продаже сейчас (сделка, снятие) — тем же правилом, что каталог; не избранное — ещё и не открыто.
            $unread = CatalogQuery::unread(Offer::query()->whereIn('offers.id', $mine->pluck('offer_id')), $user)->pluck('offers.id')->all();
            $offers = $this->onSale($user, $mine->pluck('offer_id'))
                ->filter(fn (Offer $o) => in_array($o->id, $favorites, true) || in_array($o->id, $unread, true));
            foreach ($offers->groupBy(fn (Offer $o) => $o->bids_close_at->format('Y-m-d H:i')) as $at => $pack) {
                $liked = $pack->filter(fn (Offer $o) => in_array($o->id, $favorites, true))->count();
                $user->notify(new UnreadClosingNotice($pack->count() - $liked, $liked, NotifyNewOffers::brands($pack), Carbon::parse($at),
                    $pack->count() === 1 ? $pack->first() : null));
                $sent++;
            }
        }

        return $sent;
    }

    /** За 30 минут — «Избранное» без своего подтверждения, одно громкое на человека и момент закрытия. */
    public function last(): int
    {
        $now = now();
        $rows = collect(DB::select('update offer_viewers v set last_reminded_at = ?
            from offers o, users u
            where o.id = v.offer_id and u.id = v.user_id and v.last_reminded_at is null and v.opens_at <= ?
              and o.state = ? and o.is_demo = false and o.urgent_at is null
              and o.bids_close_at > ? and o.bids_close_at <= ?
              and jsonb_exists_any(u.roles, array[?, ?]) and u.rejected_at is null
              and '.self::FAVORITE.' and not '.self::LIVE_BID.'
            returning v.user_id, v.offer_id', [
            $now, $now, OfferState::Open->value, $now, $now->copy()->addMinutes(self::LAST_MINUTES), Role::Manager->value, Role::SuperBuyer->value,
            BidState::Active->value, BidState::Accepted->value,
        ]));
        $sent = 0;
        foreach ($rows->groupBy('user_id') as $userId => $mine) {
            $user = User::find($userId);
            if (! $user) {
                continue;
            }
            foreach ($this->onSale($user, $mine->pluck('offer_id'))->groupBy(fn (Offer $o) => $o->bids_close_at->format('Y-m-d H:i')) as $pack) {
                $user->notify(new FavoriteClosingNotice($pack->count(), NotifyNewOffers::brands($pack), $pack->count() === 1 ? $pack->first() : null));
                $sent++;
            }
        }

        return $sent;
    }

    /** @return Collection<int, Offer> */
    private function onSale(User $user, Collection $ids): Collection
    {
        return Offer::visibleTo($user)->onSale()->whereIn('offers.id', $ids)->orderBy('offers.bids_close_at')->orderByDesc('offers.published_at')->get();
    }
}
