<?php

namespace App\Offers;

use App\Cars\HasLabels;

/** Как менеджер получает агентское вознаграждение: мы выплачиваем после оплаты или он удерживает его сам, оплачивая счёт за вычетом. */
enum CommissionMode: string
{
    use HasLabels;

    case Payout = 'payout';
    case Withheld = 'withheld';

    public function label(): string
    {
        return match ($this) {
            self::Payout => 'Выплачиваем',
            self::Withheld => 'Удерживает сам',
        };
    }
}
