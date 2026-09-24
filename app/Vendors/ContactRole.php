<?php

namespace App\Vendors;

use App\Cars\HasLabels;

/**
 * У одной компании пишут разные люди: эксперт по убыткам, реализация, бухгалтерия, хранение. «Реализация» —
 * собеседник CRM по продаже предложений, остальные — парковки: каждая сторона видит и правит только своих.
 */
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

    public function isSale(): bool
    {
        return $this === self::Sales;
    }

    /** @return list<self> роли стороны: CRM (продажа) или парковка */
    public static function side(bool $sale): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r->isSale() === $sale));
    }
}
