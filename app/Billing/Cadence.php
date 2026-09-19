<?php

namespace App\Billing;

use App\Cars\HasLabels;

/** Когда выставлять счёт за хранение: 1-го числа за прошлый месяц или один раз за весь период после выдачи. */
enum Cadence: string
{
    use HasLabels;

    case Monthly = 'monthly';
    case Release = 'release';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Ежемесячно',
            self::Release => 'После выдачи',
        };
    }
}
