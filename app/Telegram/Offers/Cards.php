<?php

namespace App\Telegram\Offers;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Кому какую карточку прислать (03.11.2026, владелец: «не спрашивать, показывать или нет, а просто слать»): каждое новое
 * предложение — сообщением, как только оно стало видно человеку. Менеджеру — когда наступила его волна
 * (`offer_viewers.notified_at`, её отмечает `NotifyViewers`; сдвинули волну на будущее — отметка снимается), в его регионах,
 * если он не открывал машину на сайте и не подтверждал её после этого; админу — при публикации: ему видно всё. Открытое
 * до подписки (`offer_bot_chats.cards_since`) не приходит.
 *
 * Отправленное — `offer_bot_sent` (чат и id сообщения): одна машина — одна карточка. Ушла из продажи — карточку убирает
 * `Offers\Jobs\RetractOfferNotices` вместе со строкой, и вернувшаяся машина придёт снова.
 */
final class Cards
{
    /** Пачка — сообщения подряд: звук только у первого, остальные приходят тихо. */
    private const PACK_SECONDS = 120;

    /**
     * Что пора прислать: срочные первыми, дальше — в том порядке, в каком машины стали видны.
     *
     * @return list<array{chat_id: int, offer_id: int}>
     */
    public static function due(?Carbon $now = null): array
    {
        $now ??= now();
        // Кому служит бот (`Subscriber::eligible`) и что можно слать — общее для обеих веток.
        $common = "s.blocked_at is null and u.is_demo = false
            and (u.approved_at is not null or jsonb_exists(u.roles, 'admin') or jsonb_exists(u.roles, 'moderator'))
            and o.state = 'open' and o.is_demo = false and (o.bids_close_at is null or o.bids_close_at > ?)
            and not exists (select 1 from offer_bot_sent x where x.user_id = s.user_id and x.offer_id = o.id)
            and not exists (select 1 from bids b where b.user_id = s.user_id and b.offer_id = o.id and b.state in ('active', 'accepted'))";
        $rows = DB::select("
            select s.chat_id, o.id as offer_id, o.urgent_at is not null as urgent, v.notified_at as at
            from offer_bot_chats s
            join users u on u.id = s.user_id
            join offer_viewers v on v.user_id = s.user_id
            join offers o on o.id = v.offer_id
            where jsonb_exists(u.roles, ?) and not jsonb_exists(u.roles, ?)
              and v.notified_at <= ? and v.notified_at >= s.cards_since
              and (u.offer_regions is null or o.region_id is null or u.offer_regions @> to_jsonb(o.region_id))
              and not exists (select 1 from offer_reads r where r.user_id = s.user_id and r.offer_id = o.id and r.read_at >= v.notified_at)
              and {$common}
            union all
            select s.chat_id, o.id, o.urgent_at is not null, o.published_at
            from offer_bot_chats s
            join users u on u.id = s.user_id
            join offers o on o.published_at <= ? and o.published_at >= s.cards_since
            where jsonb_exists(u.roles, ?)
              and not exists (select 1 from offer_reads r where r.user_id = s.user_id and r.offer_id = o.id and r.read_at >= o.published_at)
              and {$common}
            order by urgent desc, at, offer_id
            limit 2000", [Role::Manager->value, Role::Admin->value, $now, $now, $now, Role::Admin->value, $now]);

        return array_map(fn ($r) => ['chat_id' => (int) $r->chat_id, 'offer_id' => (int) $r->offer_id], $rows);
    }

    /** Перед отправкой — ещё раз по самой двери видимости: машина могла уйти, пока карточка ждала очереди. */
    public static function showable(User $user, Offer $offer): bool
    {
        return Offer::visibleTo($user)->whereKey($offer->id)->where('state', OfferState::Open)
            ->where(fn ($q) => $q->whereNull('bids_close_at')->orWhere('bids_close_at', '>', now()))->exists();
    }

    /** Взять пару себе до отправки: false — её уже отправили или отправляет другой процесс. */
    public static function claim(User $user, Offer $offer, int $chatId): bool
    {
        return self::table()->insertOrIgnore(['user_id' => $user->id, 'offer_id' => $offer->id, 'chat_id' => $chatId, 'created_at' => now()]) > 0;
    }

    public static function remember(User $user, Offer $offer, int $messageId): void
    {
        self::table()->where('user_id', $user->id)->where('offer_id', $offer->id)->update(['message_id' => $messageId]);
    }

    /** Не отправилась — отдать пару обратно: следующий обход часов попробует ещё раз. */
    public static function release(User $user, Offer $offer): void
    {
        self::table()->where('user_id', $user->id)->where('offer_id', $offer->id)->whereNull('message_id')->delete();
    }

    /** Карточка — продолжение пачки (предыдущая ушла ему меньше двух минут назад): без звука. */
    public static function inPack(User $user, Offer $offer): bool
    {
        return self::table()->where('user_id', $user->id)->where('offer_id', '!=', $offer->id)
            ->where('created_at', '>', now()->subSeconds(self::PACK_SECONDS))->exists();
    }

    private static function table(): Builder
    {
        return DB::connection('pgsql_async')->table('offer_bot_sent');
    }
}
