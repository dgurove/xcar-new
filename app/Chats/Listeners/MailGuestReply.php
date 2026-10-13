<?php

namespace App\Chats\Listeners;

use App\Chats\Events\ChatMessagePosted;
use App\Chats\Jobs\SendGuestReply;

/** Сотрудник ответил гостю «Написать нам» — ответ уходит и на его почту (`SendGuestReply`). */
final class MailGuestReply
{
    public function handle(ChatMessagePosted $event): void
    {
        if (SendGuestReply::due($event->message)) {
            SendGuestReply::dispatch($event->message->id);
        }
    }
}
