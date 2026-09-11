<?php

namespace App\Workflow;

use App\Cars\HasLabels;

/** Ветка маршрута: продажа двигает состояние оффера, вывоз — место машины. */
enum Track: string
{
    use HasLabels;

    case Sale = 'sale';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Продажа',
            self::Service => 'Вывоз',
        };
    }
}
