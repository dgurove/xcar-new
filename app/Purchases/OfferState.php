<?php

namespace App\Purchases;

use App\Cars\HasLabels;

enum OfferState: string
{
    use HasLabels;

    case Active = 'active';
    case Chosen = 'chosen';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ждёт',
            self::Chosen => 'Выбрана',
            self::Declined => 'Не выбрана',
            self::Withdrawn => 'Отозвана',
        };
    }
}
