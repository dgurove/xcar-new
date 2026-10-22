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
 * а вернувшийся из меню продолжает с того, что не видел. Открытое на сайте («Просмотрено», `offer_reads`) бот не
 * показывает и не считает (владелец 18.10.2026: «показывает то, что они уже видели, приходится долго листать»).
 */
final class Feed
{
    public static function query(User $user): Builder
    {
        // Регионы человека (22.10.2026) — как каталог по умолчанию: из других регионов бот не показывает.
        return Offer::visibleTo($user)->where('state', OfferState::Open)->inRegionsOf($user)
            ->where(fn ($q) => $q->whereNull('bids_close_at')->orWhere('bids_close_at', '>', now()))
            ->whereDoesntHave('bids', fn ($b) => $b->where('user_id', $user->id))
            ->whereNotExists(fn ($q) => $q->from('offer_bot_seen')->whereColumn('offer_bot_seen.offer_id', 'offers.id')->where('offer_bot_seen.user_id', $user->id))
            ->whereNotExists(fn ($q) => $q->from('offer_reads')->whereColumn('offer_reads.offer_id', 'offers.id')->where('offer_reads.user_id', $user->id));
    }

    /** «⏭️ Пропустить все»: всё, что осталось в ленте, — показанным, только в боте («Просмотрено» на сайте не ставится). */
    public static function skipAll(User $user): int
    {
        $rows = self::query($user)->pluck('offers.id')->map(fn ($id) => ['user_id' => $user->id, 'offer_id' => $id, 'reaction' => 'skip', 'created_at' => now()])->all();

        return $rows ? DB::connection('pgsql_async')->table('offer_bot_seen')->insertOrIgnore($rows) : 0;
    }

    public static function next(User $user): ?Offer
    {
        return self::query($user)->with(['settlement'])
            // Срочное с идущим приёмом — первым (12.10.2026).
            ->orderByRaw('(offers.urgent_at is not null and offers.bids_close_at > now()) desc')
            ->orderByDesc('published_at')->orderByDesc('recommended')->orderBy('id')->first();
    }

    public static function left(User $user): int
    {
        return self::query($user)->count();
    }

    /** Предложение ещё можно показать карточкой: открыто ему, в продаже, приём идёт. */
    public static function showable(User $user, Offer $offer): bool
    {
        return Offer::visibleTo($user)->whereKey($offer->id)->where('state', OfferState::Open)
            ->where(fn ($q) => $q->whereNull('bids_close_at')->orWhere('bids_close_at', '>', now()))->exists();
    }

    /** Какие сообщения — карточка этого предложения у человека (заголовок и PDF): ушло из продажи — их удалят. */
    public static function remember(User $user, Offer $offer, int $chatId, int $messageId, ?int $docId = null): void
    {
        DB::connection('pgsql_async')->table('offer_bot_seen')->where('user_id', $user->id)->where('offer_id', $offer->id)
            ->update(['chat_id' => $chatId, 'message_id' => $messageId, 'doc_message_id' => $docId]);
    }

    /** Показали (`reaction` пусто) или отреагировали: ➡️ next, 📌 pin, 💤 sleep (⏭️ skip — `skipAll`; ask — до 18.10.2026). */
    public static function mark(User $user, Offer $offer, ?string $reaction = null): void
    {
        $table = DB::connection('pgsql_async')->table('offer_bot_seen');
        $table->insertOrIgnore(['user_id' => $user->id, 'offer_id' => $offer->id, 'created_at' => now()]);
        if ($reaction) {
            $table->where('user_id', $user->id)->where('offer_id', $offer->id)->update(['reaction' => $reaction]);
        }
    }
}
