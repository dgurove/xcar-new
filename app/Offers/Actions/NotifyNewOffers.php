<?php

namespace App\Offers\Actions;

use App\Notifications\NewOffersNotice;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * «3 новых предложения» менеджеру и супер-покупателю — пачкой, когда у него 5 минут не открывалось новых (16.10.2026,
 * владелец: «уведомлять надо, но не захламлять раздел уведомлений»). Счётчик у каждого свой: момент, когда предложение
 * стало видно именно ему, — `offer_viewers.notified_at` (его ставит `NotifyViewers`); посчитанное — до
 * `users.offers_digest_at`. Ровный поток (импорт раз в 4 минуты) паузы не дал бы никогда: самое раннее неотправленное
 * старше 30 минут — уходит всё равно. Срочные и «Открыты только Вам» не входят и таймер не сдвигают: о них уже написали
 * сразу. Шаг `offers:tick` после волн.
 */
final class NotifyNewOffers
{
    /** Пауза: столько минут без нового — пора. */
    public const QUIET = 5;

    /** Предохранитель: самое раннее неотправленное старше — уходит всё равно. */
    public const LIMIT = 30;

    public function __invoke(): int
    {
        $now = now();
        $rows = DB::select('select v.user_id, min(v.notified_at) as first_at, max(v.notified_at) as last_at, array_agg(v.offer_id) as offers
            from offer_viewers v
            join users u on u.id = v.user_id
            join offers o on o.id = v.offer_id
            where v.notified_at <= ? and v.notified_at > greatest(coalesce(u.offers_digest_at, \'-infinity\'), ?)
              and jsonb_exists_any(u.roles, array[?, ?]) and u.rejected_at is null
              and o.state = ? and o.is_demo = false and o.urgent_at is null
              and exists (select 1 from offer_viewers w where w.offer_id = v.offer_id and w.user_id <> v.user_id)
            group by v.user_id
            having max(v.notified_at) <= ? or min(v.notified_at) <= ?', [
            $now, $now->copy()->subDay(), Role::Manager->value, Role::SuperBuyer->value, OfferState::Open->value,
            $now->copy()->subMinutes(self::QUIET), $now->copy()->subMinutes(self::LIMIT),
        ]);
        $sent = 0;
        foreach ($rows as $row) {
            $user = User::find($row->user_id);
            // Отметка — первой: пришедшее во время отправки уйдёт следующей пачкой, повтор тика второй раз не напишет.
            DB::table('users')->where('id', $row->user_id)->update(['offers_digest_at' => $row->last_at]);
            if (! $user) {
                continue;
            }
            $ids = array_map('intval', explode(',', trim($row->offers, '{}')));
            // Ушедшее за эти минуты (сняли, сдвинули волну, закрылся приём) не считается.
            $offers = Offer::visibleTo($user)->onSale()->whereIn('offers.id', $ids)->orderByDesc('offers.published_at')->get();
            if ($offers->isEmpty()) {
                continue;
            }
            $user->notify(new NewOffersNotice($offers->count(), $offers->take(2)->map(fn (Offer $o) => $o->title())->all(), $offers->count() === 1 ? $offers->first() : null));
            $sent++;
        }

        return $sent;
    }
}
