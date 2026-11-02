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
        if (AutoReply::due($event->message)) {
            SendAutoReply::dispatch($event->message->id);
        }
    }
}
