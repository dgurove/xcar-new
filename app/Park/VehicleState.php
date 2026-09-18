<?php

namespace App\Park;

use App\Cars\HasLabels;

enum VehicleState: string
{
    use HasLabels;

    case Expected = 'expected';
    case InTransit = 'in_transit';
    case Stored = 'stored';
    case Released = 'released';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Expected => 'Ожидается',
            self::InTransit => 'В пути',
            self::Stored => 'На стоянке',
            self::Released => 'Выдана',
            self::Cancelled => 'Не привезена',
        };
    }

    /** Конечные состояния: выданную и непривезённую не трогают. */
    public function isFinal(): bool
    {
        return $this === self::Released || $this === self::Cancelled;
    }

    /** Ещё не на площадке: ожидается или едет. */
    public function isBefore(): bool
    {
        return $this === self::Expected || $this === self::InTransit;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Expected, self::InTransit => 'urgent',
            self::Stored => 'open',
            self::Released, self::Cancelled => 'closed',
        };
    }
}
