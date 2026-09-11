<?php

namespace App\Park;

use App\Cars\HasLabels;

enum RequestState: string
{
    use HasLabels;

    case New = 'new';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Ждёт',
            self::Done => 'Выполнена',
            self::Cancelled => 'Отменена',
        };
    }
}
