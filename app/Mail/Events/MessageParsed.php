<?php

namespace App\Mail\Events;

use App\Mail\Message;
use Illuminate\Foundation\Events\Dispatchable;

final class MessageParsed
{
    use Dispatchable;

    /** `quiet` — история ящика: без тостов, бейджей и уведомлений, письмо только ложится в базу. */
    public function __construct(public Message $message, public bool $quiet = false) {}
}
