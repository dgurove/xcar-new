<?php

namespace App\Billing;

/**
 * НДС одним местом: НДС внутри суммы и код ставки в чеке ЮKassa; ставка — у продавца (`Seller::vatRate`).
 * НДС всегда внутри итога счёта: «в сумме» — строки уже с НДС, «сверху» — к строкам прибавлен НДС,
 * и в обоих случаях выделяется одинаково — итог × ставка / (100 + ставка).
 */
final class Vat
{
    /** Коды `vat_code` ЮKassa: ставка в цене → код; расчётные 5/105 и т. п. нам не нужны — чек один, полный расчёт. */
    private const RECEIPT = [0 => 2, 5 => 7, 7 => 8, 10 => 3, 20 => 4, 22 => 11];

    public static function inside(float $total, ?int $rate): float
    {
        return $rate ? round($total * $rate / (100 + $rate), 2) : 0.0;
    }

    public static function receiptCode(?int $rate): int
    {
        return $rate === null ? 1 : (self::RECEIPT[$rate] ?? 1);
    }
}
