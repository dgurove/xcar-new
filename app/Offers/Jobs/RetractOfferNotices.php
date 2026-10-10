<?php

namespace App\Offers\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Notifications\OfferPublishedNotice;
use App\Offers\Offer;
use App\Telegram\Offers\OffersBot;
use App\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * Предложение больше не открыто человеку (ушло в сделку, снято, срок вышел, выпал из волны): его анонс убирается —
 * карточка бота предложений в Telegram и «Новое предложение» в ленте. Владелец 10.10.2026: Ситрак опубликовали, через
 * две минуты он ушёл в сделку, а у менеджеров карточка с фото и ценой так и висела — «они перешли, и он доступен».
 * Кому оно по-прежнему открыто (свой резерв, белый список сделки), у того всё остаётся.
 */
final class RetractOfferNotices implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $offerId)
    {
        $this->afterCommit();
    }

    public function handle(OffersBot $bot, Publisher $publish): void
    {
        $offer = Offer::find($this->offerId);
        if (! $offer) {
            return;
        }
        $seen = DB::table('offer_bot_seen')->where('offer_id', $offer->id)->whereNotNull('message_id')->get();
        $notices = DatabaseNotification::where('type', OfferPublishedNotice::class)->where('data->offer', $offer->number)->get(['id', 'notifiable_id']);
        $ids = $seen->pluck('user_id')->merge($notices->pluck('notifiable_id'))->unique();
        if ($ids->isEmpty()) {
            return;
        }
        $gone = User::whereIn('id', $ids)->get()->reject(fn (User $u) => $offer->isVisibleTo($u))->keyBy('id');
        foreach ($seen->whereIn('user_id', $gone->keys()) as $card) {
            // Удалил сам или остановил бота — сообщения уже нет, и ладно (`deleteQuietly` пишет в лог).
            $bot->deleteQuietly((int) $card->chat_id, (int) $card->message_id);
            DB::table('offer_bot_seen')->where('user_id', $card->user_id)->where('offer_id', $offer->id)->update(['message_id' => null]);
        }
        $stale = $notices->whereIn('notifiable_id', $gone->keys());
        if ($stale->isNotEmpty()) {
            DatabaseNotification::whereIn('id', $stale->pluck('id'))->delete();
            foreach ($stale->pluck('notifiable_id')->unique() as $userId) {
                $publish->badges(Topics::user($gone[$userId]));
            }
        }
    }
}
