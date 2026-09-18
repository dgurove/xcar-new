<?php

namespace App\Vendors;

use App\Cars\HasLabels;

/**
 * Формат сделки с вендором (решение владельца): прямая — напрямую собственнику
 * или лизингу без страховой; комиссионная — оплата через нас как комиссионера;
 * поставщику — покупатель платит страховой.
 */
enum DealFormat: string
{
    use HasLabels;

    case Direct = 'direct';
    case Commission = 'commission';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Прямая',
            self::Commission => 'Комиссионная',
            self::Supplier => 'Поставщику',
        };
    }
}
