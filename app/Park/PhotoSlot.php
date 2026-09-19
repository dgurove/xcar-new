<?php

namespace App\Park;

use App\Cars\HasLabels;

/** Чек-лист кадров при приёме: четыре угла, VIN-табличка, одометр — обязательные; салон, ПТС, повреждения — по месту. */
enum PhotoSlot: string
{
    use HasLabels;

    case FrontLeft = 'front_left';
    case FrontRight = 'front_right';
    case RearLeft = 'rear_left';
    case RearRight = 'rear_right';
    case VinPlate = 'vin_plate';
    case Odometer = 'odometer';
    case Interior = 'interior';
    case Papers = 'papers';
    case Damage = 'damage';

    public function label(): string
    {
        return match ($this) {
            self::FrontLeft => 'Спереди слева',
            self::FrontRight => 'Спереди справа',
            self::RearLeft => 'Сзади слева',
            self::RearRight => 'Сзади справа',
            self::VinPlate => 'VIN',
            self::Odometer => 'Одометр',
            self::Interior => 'Салон',
            self::Papers => 'Документы',
            self::Damage => 'Повреждение',
        };
    }

    public function required(): bool
    {
        return in_array($this, [self::FrontLeft, self::FrontRight, self::RearLeft, self::RearRight, self::VinPlate, self::Odometer], true);
    }
}
