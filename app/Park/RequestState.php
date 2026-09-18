<?php

namespace App\Park;

use App\Cars\HasLabels;

enum RequestState: string
{
    use HasLabels;

    case New = 'new';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Ждёт',
            self::Scheduled => 'Назначена',
            self::InProgress => 'В работе',
            self::Done => 'Выполнена',
            self::Cancelled => 'Отменена',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Done && $this !== self::Cancelled;
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::New, self::Scheduled, self::InProgress];
    }
}
