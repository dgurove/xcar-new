<?php

namespace App\Telegram\Jobs;

use App\Telegram\Bot;
use App\Telegram\Messages\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Сообщение владельцу в Telegram. Без токена или чата — молча ничего. */
final class NotifyOwner implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public Message $message)
    {
        $this->onQueue('notifications')->afterCommit();
    }

    public function handle(Bot $bot): void
    {
        $chat = $bot->ownerChatId();
        if (! $bot->configured() || $chat === null) {
            return;
        }
        $bot->send($chat, $this->message->text(), $this->message->keyboard());
    }
}
