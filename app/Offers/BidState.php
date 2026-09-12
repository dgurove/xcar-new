<?php

namespace App\Offers;

use App\Cars\HasLabels;

enum BidState: string
{
    use HasLabels;

    case Active = 'active';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'На рассмотрении',
            self::Accepted => 'Принято',
            self::Declined => 'Отклонено',
            self::Withdrawn => 'Отозвано',
        };
    }
}
