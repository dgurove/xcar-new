<?php

namespace App\Logistics;

use App\Cars\HasLabels;
use App\Offers\CarPlace;

/**
 * Куда исполнитель везёт машину (17.10.2026, владелец: «выбор + свой адрес»): в гараж менеджера сделки, к нам, на
 * парковку или по адресу. Точка сама ставит машине место (`CarPlace`) по «Привёз». Парковка — только адрес: дело ТС
 * на park.xcar вывоз не заводит (08.10.2026, «на park.xcar — только то, что на storage@»).
 */
enum Point: string
{
    use HasLabels;

    case Keeper = 'keeper';
    case Ours = 'ours';
    case Yard = 'yard';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Keeper => 'К менеджеру',
            self::Ours => 'К нам',
            self::Yard => 'На парковку',
            self::Other => 'Другой адрес',
        };
    }

    /** Где машина, когда её привезли: у менеджера, у нас, на нашей парковке или по чужому адресу. */
    public function place(): CarPlace
    {
        return match ($this) {
            self::Keeper => CarPlace::Keeper,
            self::Ours => CarPlace::WithUs,
            self::Yard => CarPlace::Ours,
            self::Other => CarPlace::Elsewhere,
        };
    }
}
