<?php

namespace App\Mail;

use App\Cars\HasLabels;

enum SendState: string
{
    use HasLabels;

    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'В очереди',
            self::Sending => 'Отправляется',
            self::Sent => 'Отправлено',
            self::Failed => 'Не ушло',
        };
    }
}
