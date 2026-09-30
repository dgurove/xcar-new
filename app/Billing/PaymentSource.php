<?php

namespace App\Billing;

use App\Cars\HasLabels;

enum PaymentSource: string
{
    use HasLabels;

    case Bank = 'bank';
    case Cash = 'cash';
    case Acquiring = 'acquiring';
    case Offset = 'offset';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Банк',
            self::Cash => 'Наличные',
            self::Acquiring => 'Эквайринг',
            self::Offset => 'Зачёт',
        };
    }

    /** Иконка у строки оплаты; у эквайринга точнее — способ (`PayMethod::icon`). */
    public function icon(): string
    {
        return match ($this) {
            self::Bank => 'bank',
            self::Cash => 'cash',
            self::Acquiring => 'card',
            self::Offset => 'offset',
        };
    }
}
