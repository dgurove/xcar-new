<?php

namespace App\Park;

use App\Cars\HasLabels;

/** Кому выдана ТС. */
enum ReleasedTo: string
{
    use HasLabels;

    case Buyer = 'buyer';
    case Vendor = 'vendor';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Buyer => 'Покупателю',
            self::Vendor => 'Вендору',
            self::Owner => 'Страхователю',
        };
    }
}
