<?php

namespace App\Offers;

use App\Live\Publisher;
use App\Live\Topics;
use App\Support\Nav;
use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Человек открыл предложение на сайте — на его карточках «Просмотрено» (13.10.2026, менеджеры), из «Не просмотренных»
 * и числа таба уходит (21.10.2026). Пишется один раз на пару и снова после новой публикации (`offer_reads`), без
 * ожидания диска (`pgsql_async`, как `offer_bot_seen`); под «Войти как» и предзагрузкой — нет.
 */
final class ReadOffer
{
    public static function mark(?User $user, Offer $offer): void
    {
        if (! $user || Impersonation::active() || request()->prefetch()) {
            return;
        }
        // Вернулось в продажу после прошлого просмотра — отметка снова (`CatalogQuery::unread`), иначе первая остаётся.
        $fresh = DB::connection('pgsql_async')->affectingStatement('insert into offer_reads (user_id, offer_id, read_at) values (?, ?, ?)
            on conflict (user_id, offer_id) do update set read_at = excluded.read_at
            where offer_reads.read_at < (select o.published_at from offers o where o.id = excluded.offer_id)', [$user->id, $offer->id, now()]);
        if ($fresh) {
            // Число непросмотренных на табе «Предложения» гаснет сразу (`Nav::badges`), а не через полминуты кэша.
            Nav::forget($user);
            app(Publisher::class)->badges(Topics::user($user));
        }
    }

    /** Ночью: просмотры предложений, что месяц как закрыты, никому не нужны — их карточек на сайте уже нет. */
    public static function prune(): int
    {
        return DB::table('offer_reads')->whereIn('offer_id', Offer::query()->select('id')
            ->whereIn('state', [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered])
            ->where('updated_at', '<', now()->subDays(30)))->delete();
    }
}
