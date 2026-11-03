<?php

namespace App\WhatsApp;

use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Что слать в WhatsApp следующим — одно сообщение (владелец 03.11.2026, правило одно):
 * - открыто всем менеджерам (у каждого действующего волна наступила) — в группу, когда открылась последняя волна;
 * - открыто не всем — в личные каждому, кто привязал WhatsApp, когда наступила его волна (`offer_viewers.notified_at`, как у
 *   бота предложений): «только вам», эксклюзив после оценки, «Не показывать» кому-то; потом стало всем — и в группу;
 * - просьба оценить — в личные текстом.
 * Первыми — просьбы оценить (у них срок), потом личные, потом группа. Только вышедшее с `since` и после привязки человека,
 * не демо, приём идёт, не тому, кто уже подтвердил; ушедшее (или три отказа службы) — второй раз никогда.
 */
final class Queue
{
    /** Столько отказов службы на одно сообщение — и его больше не пробуем. */
    public const ATTEMPTS = 3;

    /** Действующий менеджер (`m`): от него зависит, «всем» ли открыто. */
    private const MANAGER = "m.roles @> '[\"manager\"]'::jsonb and m.approved_at is not null and m.is_demo = false";

    /** Кому WhatsApp служит (`u`) — то же, что `LinkCode::eligible`. */
    private const LINKED = "u.whatsapp_jid is not null and u.approved_at is not null and u.is_demo = false
        and (u.roles @> '[\"manager\"]'::jsonb or u.roles @> '[\"super_buyer\"]'::jsonb)";

    public function next(Carbon $since): ?Message
    {
        if ($estimate = $this->estimates($since)->first()) {
            return Message::estimate($estimate);
        }
        if ($row = $this->personal($since)) {
            return Message::personal(Offer::find($row->offer_id), User::find($row->user_id));
        }
        $offer = $this->group($since)->first();

        return $offer ? Message::group($offer) : null;
    }

    /** Открытые просьбы оценить тем, кто привязал WhatsApp, — срок ещё не вышел. */
    public function estimates(Carbon $since): Builder
    {
        return Estimate::query()->select('offer_estimates.*')->with(['offer', 'user'])
            ->join('users as u', 'u.id', '=', 'offer_estimates.user_id')
            ->where('offer_estimates.state', EstimateState::Asked)->where('offer_estimates.due_at', '>', now())
            ->whereRaw(self::LINKED)
            ->where('offer_estimates.created_at', '>=', $since)->whereColumn('offer_estimates.created_at', '>=', 'u.whatsapp_linked_at')
            ->whereRaw(self::unsent("'estimate:' || offer_estimates.id"))
            ->orderBy('offer_estimates.created_at')->orderBy('offer_estimates.id');
    }

    /** Открытое не всем — кому пора лично. */
    public function personal(Carbon $since): ?object
    {
        return DB::selectOne('
            select v.offer_id, v.user_id
            from offer_viewers v
            join users u on u.id = v.user_id
            join offers o on o.id = v.offer_id
            where '.self::LINKED.'
              and v.notified_at is not null and v.notified_at <= ? and v.notified_at >= ? and v.notified_at >= u.whatsapp_linked_at
              and '.self::selling('o').' and not ('.self::toAll('o').')
              and not exists (select 1 from bids b where b.user_id = v.user_id and b.offer_id = o.id and b.state in (\'active\', \'accepted\'))
              and '.self::unsent("'personal:' || o.id || ':' || u.id").'
            order by v.notified_at, o.id
            limit 1', [now(), $since]);
    }

    /**
     * Открытое всем — в группу. Шеринг закрыт — не в группу: в WhatsApp защиты от пересылки нет (`share_locked_by_estimate`
     * закрывает его только тому, кого просили оценить, — группе не помеха). Когда открылось всем — последняя волна.
     */
    public function group(Carbon $since): Builder
    {
        $opened = 'greatest(offers.published_at, (select max(v.opens_at) from offer_viewers v join users m on m.id = v.user_id
            where v.offer_id = offers.id and '.self::MANAGER.'))';

        return Offer::query()->whereRaw(self::selling('offers'))->where('offers.share_locked', false)
            ->whereRaw(self::toAll('offers'))
            ->whereRaw("{$opened} >= ?", [$since])
            ->whereRaw(self::unsent("'offer:' || offers.id"))
            ->orderByRaw($opened)->orderBy('offers.id');
    }

    /** В продаже, приём идёт, не демо. */
    private static function selling(string $o): string
    {
        $now = DB::getPdo()->quote(now()->toDateTimeString());

        return "{$o}.state = '".OfferState::Open->value."' and {$o}.is_demo = false and ({$o}.bids_close_at is null or {$o}.bids_close_at > {$now})";
    }

    /** Нет действующего менеджера, чья волна ещё не наступила или кому не показываем. */
    private static function toAll(string $o): string
    {
        $now = DB::getPdo()->quote(now()->toDateTimeString());

        return 'not exists (select 1 from users m where '.self::MANAGER." and not exists (select 1 from offer_viewers w
            where w.offer_id = {$o}.id and w.user_id = m.id and w.opens_at <= {$now}))";
    }

    /** Сообщение с этим ключом не ушло и не исчерпало попытки. */
    private static function unsent(string $key): string
    {
        return "not exists (select 1 from whatsapp_messages x where x.key = {$key}
            and (x.message_id is not null or x.attempts >= ".self::ATTEMPTS.'))';
    }
}
