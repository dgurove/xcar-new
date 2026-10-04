<?php

namespace App\Offers;

use App\Cars\HasLabels;

/**
 * Куда вывозят ТС. На парковку — как раньше: дело на park.xcar, хранение считается. К менеджеру и к нам — ТС стоит вне
 * парковки, хранение не считаем. Значение — и ветка исхода маршрута вывоза (`Outcome::fits`).
 */
enum Destination: string
{
    use HasLabels;

    case Keeper = 'keeper';
    case Ours = 'ours';
    case Yard = 'yard';

    public function label(): string
    {
        return match ($this) {
            self::Keeper => 'К менеджеру',
            self::Ours => 'К нам',
            self::Yard => 'На парковку',
        };
    }
}
