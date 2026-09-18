<?php

namespace App\Vendors;

use App\Cars\HasLabels;

/** Как считается наше вознаграждение: разница между продажей и назначенной ценой, процент или фикс. */
enum RewardKind: string
{
    use HasLabels;

    case Difference = 'difference';
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Difference => 'Разница цен',
            self::Percent => 'Процент',
            self::Fixed => 'Фикс',
        };
    }
}
