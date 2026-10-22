<?php

namespace App\Logistics;

use App\Cars\HasLabels;

/**
 * Дальность вывоза (18.10.2026, начальник: «внутри региона или между регионами — важно»): выбирает тот, кто назначает
 * («Кто вывозит», обязательно), видят исполнитель и логист; в таблице — вторым этажом «Куда» и чипом «Между регионами».
 */
enum Reach: string
{
    use HasLabels;

    case Local = 'local';
    case Intercity = 'intercity';

    /**
     * Один регион для «внутри региона»: Москва с областью и Петербург с Ленинградской областью — одна зона вывоза (номер
     * региона как у машин, `regions.id`), остальные — сами по себе.
     */
    public static function area(?int $region): ?int
    {
        return [50 => 77, 47 => 78][$region] ?? $region;
    }

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Внутри региона',
            self::Intercity => 'Между регионами',
        };
    }
}
