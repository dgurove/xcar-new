<?php

namespace App\Telegram\Offers;

use App\Offers\Actions\NotifyNewOffers;
use App\Users\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Часы бота предложений. Родитель (`offers-bot:run`) раз в ~20 секунд спрашивает `due()`, кому что пора, и раздаёт
 * дорожкам; дорожка проверяет ещё раз и пишет — повторная раздача безвредна.
 *
 * - Вышло слотом и стало видно менеджеру (сам слот или его поздняя волна) — «Опубликовано N предложений, показать их?».
 * - «Опубликовать сейчас» (владелец 04.10.2026) — «Появились новые предложения», те же «показать» и «напомнить».
 * - Слот и новые — когда у менеджера 5 минут не открывалось нового, но не позже 30 минут от первого (16.10.2026, как
 *   `NotifyNewOffers`); срочное — сразу, как было.
 * - «Напомнить через 1 ч».
 * Анонс в чате один: новый удаляет прежний (`prompt_message_id`), N — всё непросмотренное в ленте (`Feed::left`).
 * Утреннего «В 16:00 (МСК) будет опубликовано…» нет с 21.10.2026 (владелец: у людей разные часовые пояса, а админы почти
 * всегда публикуют сразу, не слотом).
 *
 * Рассылки — только менеджерам, подписанным и не остановившим бота.
 */
final class Announce
{
    public function __construct(private OffersBot $bot) {}

    /** @return list<array{timer: string, chat_id: int}> */
    public static function due(?Carbon $now = null): array
    {
        $now ??= now();
        $items = [];
        // Как уведомление в приложении (`NotifyNewOffers`): пачкой, когда у него 5 минут не открывалось нового, и не позже
        // 30 минут от первого; срочное — сразу. Отметка — `notified_at`: выход из галереи со старым `opens_at` тоже анонс.
        $fresh = DB::select("select s.chat_id from offer_bot_chats s
            join users u on u.id = s.user_id and jsonb_exists(u.roles, ?)
            join offer_viewers v on v.user_id = s.user_id
            join offers o on o.id = v.offer_id
            where s.blocked_at is null and s.muted_at is null
              and o.state = 'open' and o.is_demo = false
              and (o.bids_close_at is null or o.bids_close_at > ?)
              and (u.offer_regions is null or o.region_id is null or u.offer_regions @> to_jsonb(o.region_id))
              and v.notified_at <= ? and (s.announced_at is null or v.notified_at > s.announced_at)
              and not exists (select 1 from offer_bot_seen x where x.user_id = s.user_id and x.offer_id = o.id)
              and not exists (select 1 from offer_reads r where r.user_id = s.user_id and r.offer_id = o.id)
            group by s.chat_id
            having bool_or(o.urgent_at is not null) or max(v.notified_at) <= ? or min(v.notified_at) <= ?
", [Role::Manager->value, $now, $now, $now->copy()->subMinutes(NotifyNewOffers::QUIET), $now->copy()->subMinutes(NotifyNewOffers::LIMIT)]);
        foreach ($fresh as $row) {
            $items[] = ['timer' => 'announce', 'chat_id' => (int) $row->chat_id];
        }
        foreach (Subscriber::reachable()->whereNotNull('remind_at')->where('remind_at', '<=', $now)->pluck('chat_id') as $chat) {
            $items[] = ['timer' => 'remind', 'chat_id' => (int) $chat];
        }

        return $items;
    }

    /**
     * Что открылось ему с прошлого анонса и ещё не показано: всего и сколько из них слотом.
     *
     * @return array{0: int, 1: int}
     */
    public function fresh(Subscriber $sub): array
    {
        $since = $sub->announced_at;
        $offers = Feed::query($sub->user)
            ->whereHas('viewers', fn ($v) => $v->where('user_id', $sub->user_id)->where('notified_at', '<=', now())
                ->when($since, fn ($w) => $w->where('notified_at', '>', $since)))
            ->pluck('offers.slot_at');

        return [$offers->count(), $offers->filter()->count()];
    }
}
