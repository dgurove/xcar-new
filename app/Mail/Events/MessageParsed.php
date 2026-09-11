<?php

namespace App\Mail\Events;

use App\Mail\Message;
use Illuminate\Foundation\Events\Dispatchable;

final class MessageParsed
{
    use Dispatchable;

    public function __construct(public Message $message) {}
}
