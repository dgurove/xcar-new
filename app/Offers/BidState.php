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

    /** Менеджер подтвердил ещё раз другой ценой — прежнее не отозвано, а заменено новым (07.10.2026). В списках его нет. */
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'На рассмотрении',
            self::Accepted => 'Принято',
            self::Declined => 'Отклонено',
            self::Withdrawn => 'Отозвано',
            self::Replaced => 'Изменено',
        };
    }
}
