<?php

namespace App\Cars;

enum Fuel: string
{
    use HasLabels;

    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case Electric = 'electric';
    case Gas = 'gas';

    public function label(): string
    {
        return match ($this) {
            self::Petrol => 'Бензин',
            self::Diesel => 'Дизель',
            self::Hybrid => 'Гибрид',
            self::Electric => 'Электро',
            self::Gas => 'Газ',
        };
    }
}
