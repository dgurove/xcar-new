<?php

namespace App\Telegram\Jobs;

use App\Telegram\Bot;
use App\Telegram\Messages\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Сообщение владельцу в Telegram: в чат из настроек и привязанным админам. Без токена или чатов — молча ничего.
 * Повтор очередью — только если не дошло никуда: иначе дошедшие получили бы его дважды.
 */
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
        $chats = $bot->configured() ? $bot->ownerChats() : [];
        $failed = null;
        foreach ($chats as $chat) {
            try {
                $bot->send($chat, $this->message->text(), $this->message->keyboard());
                $sent = true;
            } catch (Throwable $e) {
                Log::warning('Telegram: сообщение владельцу не ушло', ['chat' => $chat, 'error' => $e->getMessage()]);
                $failed = $e;
            }
        }
        if ($failed && ! isset($sent)) {
            throw $failed;
        }
    }
}
