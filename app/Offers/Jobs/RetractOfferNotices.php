<?php

namespace App\Offers\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Notifications\BuyerOffersNotice;
use App\Notifications\DealOpenedNotice;
use App\Notifications\GalleryInterestOnSaleNotice;
use App\Notifications\Notice;
use App\Notifications\OfferPublishedNotice;
use App\Notifications\OffersShownNotice;
use App\Notifications\PurchaseCarOnSaleNotice;
use App\Notifications\UrgentOfferNotice;
use App\Offers\Offer;
use App\Telegram\Bot;
use App\Telegram\ChatMessage;
use App\Telegram\Offers\OffersBot;
use App\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Предложение больше не открыто человеку (ушло в сделку, снято, срок вышел, выпал из волны): его анонс убирается —
 * карточка бота предложений в Telegram и «Новое предложение» в ленте. Владелец 10.10.2026: Ситрак опубликовали, через
 * две минуты он ушёл в сделку, а у менеджеров карточка с фото и ценой так и висела — «они перешли, и он доступен».
 * Кому оно по-прежнему открыто (свой резерв, белый список сделки), у того всё остаётся. С 13.10.2026 — все уведомления
 * о предложении (`TYPES`) и их сообщения основного бота (`Notice::offerSubject`); зовут и при сдвиге волны на будущее.
 */
final class RetractOfferNotices implements ShouldQueue
{
    use Queueable;

    /** Уведомления «о предложении целиком»: их смысл — «вот машина, смотрите». */
    private const TYPES = [OfferPublishedNotice::class, UrgentOfferNotice::class, PurchaseCarOnSaleNotice::class,
        GalleryInterestOnSaleNotice::class, BuyerOffersNotice::class, DealOpenedNotice::class, OffersShownNotice::class];

    public function __construct(public int $offerId)
    {
        $this->afterCommit();
    }

    /** Человеку сузили круг (группа, режим «Выбранные», передача покупателя): проверить каждую машину его уведомлений. */
    public static function forUser(User $user): void
    {
        DatabaseNotification::whereIn('type', self::TYPES)->where('notifiable_type', User::class)->where('notifiable_id', $user->id)
            ->whereNotNull('data->offer')->pluck('data')
            ->map(fn ($d) => (is_array($d) ? $d : json_decode($d, true))['offer'] ?? null)->filter()->unique()
            ->each(fn ($number) => ($id = Offer::where('number', $number)->value('id')) ? self::dispatch($id) : null);
    }

    public function handle(OffersBot $bot, Bot $main, Publisher $publish): void
    {
        $offer = Offer::find($this->offerId);
        if (! $offer) {
            return;
        }
        $seen = DB::table('offer_bot_seen')->where('offer_id', $offer->id)->whereNotNull('message_id')->get();
        $notices = DatabaseNotification::whereIn('type', self::TYPES)->where('data->offer', $offer->number)->get(['id', 'notifiable_id']);
        $sent = ChatMessage::where('subject', Notice::offerSubject($offer->id))->where('direction', ChatMessage::OUT)->whereNull('deleted_at')->get();
        $chats = $sent->isEmpty() ? collect() : User::whereIn('telegram_chat_id', $sent->pluck('chat_id')->unique())->pluck('id', 'telegram_chat_id');
        // Показанное ботом до 10.10.2026 — без номера сообщения: карточку не убрать, но строка «показано» уходит, иначе
        // вернувшуюся в его волну машину бот ему больше не покажет.
        $blind = DB::table('offer_bot_seen')->where('offer_id', $offer->id)->whereNull('message_id')->pluck('user_id');
        $ids = $seen->pluck('user_id')->merge($notices->pluck('notifiable_id'))->merge($chats->values())->merge($blind)->unique();
        if ($ids->isEmpty() && $sent->isEmpty()) {
            return;
        }
        $gone = User::whereIn('id', $ids)->get()->reject(fn (User $u) => $offer->isVisibleTo($u))->keyBy('id');
        foreach ($seen->whereIn('user_id', $gone->keys()) as $card) {
            // Убрали (удалили или старше 48 ч — переписали подпись) — строка «показано» уходит целиком: вернётся машина в
            // продажу, бот покажет её снова. Не вышло — строка остаётся, следующая смена состояния попробует ещё.
            // PDF для пересылки (`PdfCard`) — вторым сообщением карточки: убрать и его.
            if ($card->doc_message_id ?? null) {
                $bot->retract((int) $card->chat_id, (int) $card->doc_message_id);
            }
            if ($bot->retract((int) $card->chat_id, (int) $card->message_id)) {
                DB::table('offer_bot_seen')->where('user_id', $card->user_id)->where('offer_id', $offer->id)->delete();
            }
        }
        DB::table('offer_bot_seen')->where('offer_id', $offer->id)->whereNull('message_id')->whereIn('user_id', $gone->keys())->delete();
        // Основной бот: «Новое предложение», «Срочно» и прочие о машине — у тех, кому она больше не видна.
        foreach ($sent as $message) {
            // Чат больше ничей (перепривязали Telegram) — сообщение о машине там некому видеть законно.
            $owner = $chats->get($message->chat_id);
            if ($owner && ! $gone->has($owner)) {
                continue;
            }
            try {
                $main->delete((int) $message->chat_id, (int) $message->message_id);
            } catch (Throwable $e) {
                Log::info('Telegram: уведомление о предложении не удалилось', ['message' => $message->id, 'error' => $e->getMessage()]);
            }
            $message->update(['deleted_at' => now()]);
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
