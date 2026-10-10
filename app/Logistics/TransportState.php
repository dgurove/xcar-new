<?php

namespace App\Logistics;

use App\Cars\HasLabels;

/** Ход перевозки: ждёт логиста (межрегион без исполнителя) → назначена → в пути («Забрал») → доставлена («Сдал»). */
enum TransportState: string
{
    use HasLabels;

    case New = 'new';
    case Assigned = 'assigned';
    case Moving = 'moving';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Ждёт логиста',
            self::Assigned => 'Назначена',
            self::Moving => 'В пути',
            self::Delivered => 'Доставлена',
            self::Cancelled => 'Отменена',
        };
    }

    /** Цвет слова в строке: ждёт — тревожно, в пути — акцент, конец — серый. */
    public function tone(): string
    {
        return match ($this) {
            self::New => 'text-urgent',
            self::Assigned => 'text-ink',
            self::Moving => 'text-accent-text',
            self::Delivered, self::Cancelled => 'text-ink-muted',
        };
    }
}
