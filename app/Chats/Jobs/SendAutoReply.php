<?php

namespace App\Chats\Jobs;

use App\Chats\Actions\AutoReply;
use App\Chats\Message;
use App\Live\Publisher;
use App\Live\Topics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Через пару секунд после сообщения площадке — «печатает», ещё через три — автоответ, если сотрудник не успел сам. */
final class SendAutoReply implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId)
    {
        $this->delay(2);
    }

    public function handle(Publisher $publish): void
    {
        $message = Message::with('chat')->find($this->messageId);
        if (! $message || ! AutoReply::due($message)) {
            return;
        }
        $chat = $message->chat;
        $publish($chat->user_id ? Topics::user($chat->user_id) : Topics::chat($chat->id), 'chat-typing', ['chat' => $chat->id, 'user' => null]);
        sleep(3);
        if (AutoReply::due($message->refresh())) {
            app(AutoReply::class)($chat);
        }
    }
}
