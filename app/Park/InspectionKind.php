<?php

namespace App\Park;

use App\Cars\HasLabels;

enum InspectionKind: string
{
    use HasLabels;

    case Intake = 'intake';
    case Release = 'release';
    case Pickup = 'pickup';

    public function label(): string
    {
        return match ($this) {
            self::Intake => 'При приёме',
            self::Release => 'При выдаче',
            self::Pickup => 'При погрузке',
        };
    }
}
