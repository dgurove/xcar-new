<?php

namespace App\Chats\Events;

use App\Chats\Message;
use Illuminate\Foundation\Events\Dispatchable;

final class ChatMessagePosted
{
    use Dispatchable;

    public function __construct(public Message $message) {}
}
