<?php

namespace App\Workflow;

use App\Cars\HasLabels;

/**
 * Ветка маршрута. Осталась одна — продажа: вывоз с 17.10.2026 — задача логистики (`Logistics\Transport`), а не маршрут.
 */
enum Track: string
{
    use HasLabels;

    case Sale = 'sale';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Продажа',
        };
    }
}
