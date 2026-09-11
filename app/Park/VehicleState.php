<?php

namespace App\Park;

use App\Cars\HasLabels;

enum VehicleState: string
{
    use HasLabels;

    case Expected = 'expected';
    case Stored = 'stored';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Expected => 'Ожидается',
            self::Stored => 'На стоянке',
            self::Released => 'Выдана',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Expected => 'urgent',
            self::Stored => 'open',
            self::Released => 'closed',
        };
    }
}
