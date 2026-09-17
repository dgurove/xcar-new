<?php

namespace App\Chats\Events;

use App\Chats\Chat;
use Illuminate\Foundation\Events\Dispatchable;

/** Сторона дочитала чат до seq: у другой стороны галочки на своих сообщениях становятся двойными. */
final class ChatRead
{
    use Dispatchable;

    public function __construct(public Chat $chat, public bool $byCounterpart, public int $seq) {}
}
