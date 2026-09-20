<?php

namespace App\Mail;

use App\Cars\HasLabels;

/** Чей ящик: офферов (xcar.ru) или стоянки (park.xcar.ru). */
enum Scope: string
{
    use HasLabels;

    case Offers = 'offers';
    case Park = 'park';

    public function label(): string
    {
        return match ($this) {
            self::Offers => 'Предложения',
            self::Park => 'Парковка',
        };
    }
}
