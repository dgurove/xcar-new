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

    /**
     * Ставки на выбор — договор, счёт за ТС, НДС поставщика (20.11.2026, бухгалтер: «в счёте за ТС — как у того, у кого забираем
     * машину: без НДС, 22 %, 7 % и т. д., на всю сумму»). Те же, что знает чек.
     */
    public const RATES = [0, 5, 7, 10, 20, 22];

    /** «Без НДС» / «22 %». */
    public static function label(?int $rate): string
    {
        return $rate ? $rate."\u{00A0}%" : 'Без НДС';
    }

    public static function inside(float $total, ?int $rate): float
    {
        return $rate ? round($total * $rate / (100 + $rate), 2) : 0.0;
    }

    public static function receiptCode(?int $rate): int
    {
        return $rate === null ? 1 : (self::RECEIPT[$rate] ?? 1);
    }
}
