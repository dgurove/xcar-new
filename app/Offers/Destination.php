<?php

namespace App\Offers;

use App\Cars\HasLabels;

/**
 * Куда вывозят ТС: к менеджеру или к нам. Парковки здесь нет (08.10.2026, владелец: «в park.xcar попадает только то,
 * что на storage@»): вывоз CRM дел и заявок на park.xcar не заводит. Значение — и ветка исхода маршрута вывоза
 * (`Outcome::fits`).
 */
enum Destination: string
{
    use HasLabels;

    case Keeper = 'keeper';
    case Ours = 'ours';

    public function label(): string
    {
        return match ($this) {
            self::Keeper => 'К менеджеру',
            self::Ours => 'К нам',
        };
    }
}
