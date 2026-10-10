<?php

namespace App\Logistics;

use App\Cars\HasLabels;

/**
 * Кто везёт: партнёр в регионе (менеджер xcar, за стоимость эвакуации), наш водитель, внешний перевозчик (автовоз,
 * транспортная компания — только между регионами, его вписывает логист).
 */
enum Executor: string
{
    use HasLabels;

    case Partner = 'partner';
    case Driver = 'driver';
    case Carrier = 'carrier';

    public function label(): string
    {
        return match ($this) {
            self::Partner => 'Партнёр',
            self::Driver => 'Наш эвакуатор',
            self::Carrier => 'Перевозчик',
        };
    }
}
