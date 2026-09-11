<?php

namespace App\Cars;

enum DamageCause: string
{
    use HasLabels;

    case Accident = 'accident';
    case Fire = 'fire';
    case Flood = 'flood';
    case Hail = 'hail';
    case TheftRecovered = 'theft_recovered';
    case Vandalism = 'vandalism';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Accident => 'ДТП',
            self::Fire => 'Пожар',
            self::Flood => 'Вода',
            self::Hail => 'Град',
            self::TheftRecovered => 'Возврат после угона',
            self::Vandalism => 'Повреждение третьими лицами',
            self::Other => 'Другое',
        };
    }
}
