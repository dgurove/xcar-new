<?php

namespace App\Logistics;

use App\Cars\HasLabels;

/** Перевозка внутри региона или между регионами — первый вопрос развилки (владелец 10.10.2026). */
enum Scope: string
{
    use HasLabels;

    case Local = 'local';
    case Intercity = 'intercity';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Внутри региона',
            self::Intercity => 'Между регионами',
        };
    }
}
