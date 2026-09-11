<?php

namespace App\Cars;

enum Transmission: string
{
    use HasLabels;

    case Manual = 'manual';
    case Automatic = 'automatic';
    case Cvt = 'cvt';
    case DualClutch = 'dual_clutch';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Механическая',
            self::Automatic => 'Автоматическая',
            self::Cvt => 'Вариатор',
            self::DualClutch => 'Робот',
        };
    }
}
