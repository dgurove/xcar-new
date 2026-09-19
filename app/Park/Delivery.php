<?php

namespace App\Park;

use App\Cars\HasLabels;

/** Как ТС попадёт на стоянку — решается по телефону со страхователем. */
enum Delivery: string
{
    use HasLabels;

    case Tow = 'tow';
    case Self = 'self';

    public function label(): string
    {
        return match ($this) {
            self::Tow => 'Эвакуатор',
            self::Self => 'Привезёт сам',
        };
    }
}
