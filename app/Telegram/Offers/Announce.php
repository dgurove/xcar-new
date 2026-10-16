<?php

namespace App\Telegram\Offers;

use App\Offers\Actions\NotifyNewOffers;
use App\Offers\AudienceRules;
use App\Offers\Offer;
use App\Offers\Slots;
use App\Support\Plural;
use App\Users\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Часы бота предложений. Родитель (`offers-bot:run`) раз в ~20 секунд спрашивает `due()`, кому что пора, и раздаёт
 * дорожкам; дорожка проверяет ещё раз и пишет — повторная раздача безвредна.
 *
 * - 13:00–16:00 — «В 16:00 (МСК) будет опубликовано N предложений. Не пропустите», раз в день, если N > 0: в слоте на
 *   сегодня и этому менеджеру показывают сразу (волна 0).
 * - Вышло слотом и стало видно менеджеру (сам слот или его поздняя волна) — «Опубликовано N предложений, показать их?».
 * - «Опубликовать сейчас» (владелец 04.10.2026) — «Появились новые предложения», те же «показать» и «напомнить».
 * - Слот и новые — когда у менеджера 5 минут не открывалось нового, но не позже 30 минут от первого (16.10.2026, как
 *   `NotifyNewOffers`); срочное — сразу, как было.
 * - «Напомнить через 1 ч».
 * Анонс в чате один: новый удаляет прежний (`prompt_message_id`), N — всё непросмотренное в ленте (`Feed::left`).
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
        $slot = $now->copy()->setTime((int) config('xcar.slot_hour'), 0);
        if ($now->hour >= 13 && $now->lt($slot)) {
            $chats = DB::connection('pgsql_async')->table('offer_bot_chats as s')->join('users as u', 'u.id', '=', 's.user_id')
                ->whereNull('s.blocked_at')->whereNull('s.muted_at')->whereRaw('jsonb_exists(u.roles, ?)', [Role::Manager->value])
                ->where(fn ($q) => $q->whereNull('s.morning_on')->orWhere('s.morning_on', '<', $now->toDateString()))->pluck('s.chat_id');
            foreach ($chats as $chat) {
                $items[] = ['timer' => 'morning', 'chat_id' => (int) $chat];
            }
        }
        // Как уведомление в приложении (`NotifyNewOffers`): пачкой, когда у него 5 минут не открывалось нового, и не позже
        // 30 минут от первого; срочное — сразу. Отметка — `notified_at`: выход из галереи со старым `opens_at` тоже анонс.
        $fresh = DB::select("select s.chat_id from offer_bot_chats s
            join users u on u.id = s.user_id and jsonb_exists(u.roles, ?)
            join offer_viewers v on v.user_id = s.user_id
            join offers o on o.id = v.offer_id
            where s.blocked_at is null and s.muted_at is null
              and o.state = 'open' and o.is_demo = false
              and (o.bids_close_at is null or o.bids_close_at > ?)
              and v.notified_at <= ? and (s.announced_at is null or v.notified_at > s.announced_at)
              and not exists (select 1 from offer_bot_seen x where x.user_id = s.user_id and x.offer_id = o.id)
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

    /** 13:00: что выйдет сегодня в 16:00 и откроется ему сразу. Отметка дня — в любом случае, считать раз в день. */
    public function morning(Subscriber $sub): void
    {
        if ($sub->morning_on?->isToday() || $sub->muted_at || ! $sub->user->isManager()) {
            return;
        }
        $sub->forceFill(['morning_on' => today()])->save();
        $slot = Slots::nearest(now()->copy()->setTime(12, 0));
        $n = collect(self::openingsAt($slot))->filter(fn (array $open) => ($open[$sub->user_id] ?? null) === 0)->count();
        if ($n > 0) {
            $this->bot->quietly(fn () => $this->bot->say($sub->chat_id,
                "В 16:00 (МСК) будет опубликовано {$n} ".Plural::of($n, ['предложение', 'предложения', 'предложений']).'. Не пропустите'));
        }
    }

    /**
     * Кому и через сколько откроется каждое предложение слота — один расчёт на всех (дорожки берут из кэша).
     *
     * @return array<int, array<int, int>> offer_id → [manager_id → задержка, мин]
     */
    private static function openingsAt(Carbon $slot): array
    {
        return Cache::remember('offers-bot:openings:'.$slot->format('YmdHi'), 300, function () use ($slot) {
            $people = null;

            return Offer::scheduled()->where('slot_at', $slot)->with('vendor')->get()
                ->mapWithKeys(function (Offer $o) use (&$people) {
                    return [$o->id => AudienceRules::openings(AudienceRules::of($o), $people)];
                })->all();
        });
    }
}
