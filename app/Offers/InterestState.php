<?php

namespace App\Offers;

use App\Cars\HasLabels;

enum InterestState: string
{
    use HasLabels;

    case New = 'new';
    case Contacted = 'contacted';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новый',
            self::Contacted => 'Связались',
            self::Closed => 'Закрыт',
        };
    }
}
