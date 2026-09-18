<?php

namespace App\Vendors;

use App\Cars\HasLabels;

/** У одной компании пишут разные люди: эксперт по убыткам, реализация, бухгалтерия, хранение. */
enum ContactRole: string
{
    use HasLabels;

    case Claims = 'claims';
    case Sales = 'sales';
    case Accounting = 'accounting';
    case Storage = 'storage';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Claims => 'Убытки',
            self::Sales => 'Реализация',
            self::Accounting => 'Бухгалтерия',
            self::Storage => 'Хранение',
            self::Other => 'Контакт',
        };
    }
}
