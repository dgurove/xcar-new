<?php

namespace App\Offers;

use App\Cars\HasLabels;

enum DealState: string
{
    use HasLabels;

    case Active = 'active';
    case Done = 'done';
    case Cancelled = 'cancelled';
    // Поручена параллельно (07.11.2026): менеджер ведёт её как свою, о главной не знает; маршрута у неё нет — теневой шаг.
    case Parallel = 'parallel';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Идёт',
            self::Done => 'Завершена',
            self::Cancelled => 'Отменена',
            self::Parallel => 'Параллельно',
        };
    }
}
