<?php

namespace App\Billing;

use App\Cars\HasLabels;

/** Просрочен и частично оплачен — не состояния, а вычисляются по датам и суммам. */
enum InvoiceState: string
{
    use HasLabels;

    case Issued = 'issued';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Выставлен',
            self::Paid => 'Оплачен',
            self::Void => 'Аннулирован',
        };
    }
}
