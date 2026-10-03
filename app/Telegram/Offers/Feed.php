<?php

namespace App\Telegram\Offers;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Лента бота: что менеджер видит на сайте, в продаже, приём открыт, своего подтверждения нет и бот это ему ещё не
 * показывал. Новые сверху, рекомендуемые раньше прочих. Показанное помечается сразу при показе — «и ещё N» убывает,
 * а вернувшийся из меню продолжает с того, что не видел.
 */
final class Feed
{
    public static function query(User $user): Builder
    {
        return Offer::visibleTo($user)->where('state', OfferState::Open)
            ->where(fn ($q) => $q->whereNull('bids_close_at')->orWhere('bids_close_at', '>', now()))
            ->whereDoesntHave('bids', fn ($b) => $b->where('user_id', $user->id))
            ->whereNotExists(fn ($q) => $q->from('offer_bot_seen')->whereColumn('offer_bot_seen.offer_id', 'offers.id')->where('offer_bot_seen.user_id', $user->id));
    }

    public static function next(User $user): ?Offer
    {
        return self::query($user)->with(['brand', 'model', 'settlement'])
            ->orderByDesc('published_at')->orderByDesc('recommended')->orderBy('id')->first();
    }

    public static function left(User $user): int
    {
        return self::query($user)->count();
    }

    /** Показали (`reaction` пусто) или отреагировали: ➡️ next, 📌 pin, 💬 ask, 💤 sleep. */
    public static function mark(User $user, Offer $offer, ?string $reaction = null): void
    {
        $table = DB::connection('pgsql_async')->table('offer_bot_seen');
        $table->insertOrIgnore(['user_id' => $user->id, 'offer_id' => $offer->id, 'created_at' => now()]);
        if ($reaction) {
            $table->where('user_id', $user->id)->where('offer_id', $offer->id)->update(['reaction' => $reaction]);
        }
    }
}
