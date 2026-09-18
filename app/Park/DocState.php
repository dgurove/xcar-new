<?php

namespace App\Park;

use App\Cars\HasLabels;

enum DocState: string
{
    use HasLabels;

    case Pending = 'pending';
    case Sent = 'sent';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ещё нет',
            self::Sent => 'Отправлен',
            self::Received => 'Получен',
        };
    }
}
