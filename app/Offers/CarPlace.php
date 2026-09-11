<?php

namespace App\Offers;

use App\Cars\HasLabels;

enum CarPlace: string
{
    use HasLabels;

    case Owner = 'owner';
    case Moving = 'moving';
    case Ours = 'ours';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'У владельца',
            self::Moving => 'В пути к нам',
            self::Ours => 'На нашей площадке',
        };
    }
}
