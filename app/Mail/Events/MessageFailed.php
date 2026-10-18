<?php

namespace App\Mail\Events;

use App\Mail\Message;
use Illuminate\Foundation\Events\Dispatchable;

/** Письмо не ушло с нашей стороны (SMTP отказал, обрыв): шаг сделки показывает это красным (`MarkSupplierLetter`). */
final class MessageFailed
{
    use Dispatchable;

    public function __construct(public Message $message) {}
}
