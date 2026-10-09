<?php

namespace App\Park;

use App\Cars\HasLabels;

enum VehicleState: string
{
    use HasLabels;

    case Expected = 'expected';
    case Stored = 'stored';
    case Released = 'released';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Expected => 'Ожидается',
            self::Stored => 'На парковке',
            self::Released => 'Выдана',
            self::Cancelled => 'Не привезена',
        };
    }

    /** Конечные состояния: выданную и непривезённую не трогают. */
    public function isFinal(): bool
    {
        return $this === self::Released || $this === self::Cancelled;
    }

    /** Ещё не на площадке: ожидается (едущей больше нет — эвакуацию сняли 10.10.2026). */
    public function isBefore(): bool
    {
        return $this === self::Expected;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Expected => 'urgent',
            self::Stored => 'open',
            self::Released, self::Cancelled => 'closed',
        };
    }
}
