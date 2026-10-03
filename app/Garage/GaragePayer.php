<?php

namespace App\Garage;

use App\Cars\HasLabels;

/**
 * Кто платит поставщику за машину, уходящую в гараж. Решает админ при принятии. Мы — маршрут идёт гаражной
 * веткой (оплачиваем, документы на нас), закупочная — «отдали за». Менеджер — путь обычной сделки, а его оплата
 * поставщику встаёт в гараж строкой расхода сама.
 */
enum GaragePayer: string
{
    use HasLabels;

    case Us = 'us';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Us => 'Мы',
            self::Manager => 'Менеджер',
        };
    }
}
