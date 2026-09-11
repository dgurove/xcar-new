<?php

namespace App\Offers;

use App\Cars\HasLabels;

enum DealState: string
{
    use HasLabels;

    case Active = 'active';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Идёт',
            self::Done => 'Завершена',
            self::Cancelled => 'Отменена',
        };
    }
}
