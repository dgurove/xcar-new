<?php

namespace App\Offers;

use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Человек открыл предложение на сайте — на его карточках «Просмотрено» (13.10.2026, менеджеры). Пишется один раз на пару
 * (`offer_reads`), без ожидания диска (`pgsql_async`, как `offer_bot_seen`); под «Войти как» и предзагрузкой — нет.
 */
final class ReadOffer
{
    public static function mark(?User $user, Offer $offer): void
    {
        if (! $user || Impersonation::active() || request()->prefetch()) {
            return;
        }
        DB::connection('pgsql_async')->table('offer_reads')->insertOrIgnore(['user_id' => $user->id, 'offer_id' => $offer->id, 'read_at' => now()]);
    }

    /** Ночью: просмотры предложений, что месяц как закрыты, никому не нужны — их карточек на сайте уже нет. */
    public static function prune(): int
    {
        return DB::table('offer_reads')->whereIn('offer_id', Offer::query()->select('id')
            ->whereIn('state', [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered])
            ->where('updated_at', '<', now()->subDays(30)))->delete();
    }
}
