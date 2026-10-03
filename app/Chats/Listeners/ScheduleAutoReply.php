<?php

namespace App\Chats\Listeners;

use App\Chats\Actions\AutoReply;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Jobs\SendAutoReply;

/** Сообщение площадке без живого ответа сотрудника — в очередь на автоответ. */
final class ScheduleAutoReply
{
    public function handle(ChatMessagePosted $event): void
    {
        // Вопрос из бота предложений: бот уже ответил «ответ придёт сюда» — автоответ на сайте лишний.
        if (! $event->message->via_bot && AutoReply::due($event->message)) {
            SendAutoReply::dispatch($event->message->id);
        }
    }
}
