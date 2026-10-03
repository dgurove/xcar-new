<?php

namespace App\Telegram\Offers;

use App\Chats\AuthorKind;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Сотрудник ответил в переписке, начатой в боте предложений (реплаем в Telegram или из CRM), — остальным админам,
 * подписанным на бота, тихо под их копией вопроса: «Дмитрий Гуров ответил: …». В очереди: отвечающий из CRM не ждёт
 * Telegram.
 */
final class RelayAnswer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $messageId)
    {
        $this->onQueue('notifications');
    }

    /** Слушатель `ChatMessagePosted`: в очередь — только ответ сотрудника по переписке из бота. */
    public static function on(ChatMessagePosted $e): void
    {
        $m = $e->message;
        if ($m->author_kind === AuthorKind::Staff && $m->author_id && $m->chat->offer_id
            && app(OffersBot::class)->configured() && OffersBotChannel::botThread($m->chat_id)) {
            self::dispatch($m->id);
        }
    }

    public function handle(Questions $questions): void
    {
        if ($message = Message::with(['chat.offer', 'author'])->find($this->messageId)) {
            $questions->relay($message);
        }
    }
}
