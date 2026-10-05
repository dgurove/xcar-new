<?php

namespace App\Vendors;

use App\Cars\HasLabels;

/**
 * Формат сделки с вендором (решение владельца): прямая — напрямую собственнику
 * или лизингу без страховой; комиссионная — оплата через нас как комиссионера;
 * поставщику — покупатель платит страховой. С 05.10.2026 — схема сделки по умолчанию (`DealScheme::forVendor`):
 * прямая — «страхователю по ДКП», комиссионная — «ПРАЙМ по счёту», поставщику — «страховой напрямую»; решает админ.
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
            self::Direct => 'Прямая, страхователю по ДКП',
            self::Commission => 'Комиссионная, ПРАЙМ по счёту',
            self::Supplier => 'Поставщику, страховой напрямую',
        };
    }
}
