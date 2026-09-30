<?php

namespace App\Billing;

use App\Park\Vehicle;

/**
 * Кто выставляет счёт — одно из наших лиц. Парковка — бизнес ИП Кузнецова, сделки и гараж — ООО «ПРАЙМ»
 * (решение владельца 30.09.2026). У каждого свои реквизиты (`billing_parties.seller`), своя ставка НДС и
 * своя нумерация счетов. Продавца определяет сам счёт: по ТС парковки — ИП, остальное — ПРАЙМ.
 */
enum Seller: string
{
    case Prime = 'prime';
    case Park = 'park';

    public static function for(?Vehicle $vehicle): self
    {
        return $vehicle ? self::Park : self::Prime;
    }

    /** Реквизиты из конфига: ими печатаются акты парковки и заводится строка `billing_parties` при первом счёте. */
    public function config(): array
    {
        return config($this === self::Prime ? 'xcar.company' : 'xcar.park_company', []);
    }

    /** Ставка НДС продавца; 0 — без НДС. У обоих УСН с НДС 5 %. */
    public function vatRate(): int
    {
        return (int) ($this->config()['vat_rate'] ?? 0);
    }

    public function party(): Party
    {
        return Party::seller($this);
    }
}
