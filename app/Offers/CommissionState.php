<?php

namespace App\Offers;

/** Где вознаграждение менеджера по сделке: скрыто до счёта, ждёт оплаты, к выплате, выплачено или удержано им самим. */
enum CommissionState: string
{
    case Hidden = 'hidden';
    case Awaiting = 'awaiting';
    case Payable = 'payable';
    case Paid = 'paid';
    case Withheld = 'withheld';

    public function label(): string
    {
        return match ($this) {
            self::Hidden => '',
            self::Awaiting => 'Ждёт оплаты счёта',
            self::Payable => 'К выплате',
            self::Paid => 'Выплачено',
            self::Withheld => 'Удержано',
        };
    }

    /** Тон пилюли: к выплате — лайм, выплачено — закрыто, ждёт — серый. */
    public function tone(): string
    {
        return match ($this) {
            self::Payable => 'open',
            self::Paid, self::Withheld => 'closed',
            default => 'plain',
        };
    }
}
