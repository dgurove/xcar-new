<?php

namespace App\Chats\Events;

use App\Chats\Message;
use Illuminate\Foundation\Events\Dispatchable;

/** Сообщение поправили или удалили: обеим сторонам перечитать один пузырь. */
final class ChatMessageChanged
{
    use Dispatchable;

    public function __construct(public Message $message) {}
}
