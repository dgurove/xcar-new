<?php

namespace App\Billing;

use App\Park\Vehicle;
use App\Vendors\Vendor;

/**
 * Кто выставляет счёт — одно из наших лиц. Парковка — бизнес ИП Кузнецова, сделки и гараж — ООО «ПРАЙМ»
 * (решение владельца 30.09.2026). У каждого свои реквизиты (`billing_parties.seller`), своя ставка НДС и
 * своя нумерация счетов. Продавца определяет сам счёт: по ТС парковки — продавец парковки её вендора (ИП, у Альфы СПб —
 * ПРАЙМ), остальное — ПРАЙМ.
 */
enum Seller: string
{
    case Prime = 'prime';
    case Park = 'park';

    public static function for(?Vehicle $vehicle): self
    {
        return $vehicle ? self::ofVendor($vehicle->vendor) : self::Prime;
    }

    /**
     * Продавец парковки у вендора (`vendors.park_seller`): по умолчанию ИП, у Альфы СПб — ПРАЙМ (Питер всегда выставлял
     * от него, владелец 09.10.2026). Им же печатаются акты и договоры по ТС этого вендора.
     */
    public static function ofVendor(?Vendor $vendor): self
    {
        return $vendor?->park_seller ?? self::Park;
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

    /** Выбор «Счета от» у вендора парковки: короткие имена из реквизитов. @return array<string, string> */
    public static function options(): array
    {
        return array_combine(array_map(fn (self $s) => $s->value, self::cases()), array_map(fn (self $s) => (string) ($s->config()['short'] ?? $s->config()['name'] ?? $s->value), self::cases()));
    }

    public function party(): Party
    {
        return Party::seller($this);
    }
}
