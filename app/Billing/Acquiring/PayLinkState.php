<?php

namespace App\Billing\Acquiring;

use App\Cars\HasLabels;

enum PayLinkState: string
{
    use HasLabels;

    case Open = 'open';
    case Paid = 'paid';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ждём оплату',
            self::Paid => 'Оплачено',
            self::Canceled => 'Отменена',
        };
    }
}
