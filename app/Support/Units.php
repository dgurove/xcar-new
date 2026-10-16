<?php

namespace App\Support;

/**
 * Пробег, объём и мощность словами — одна дверь (16.10.2026: около десяти мест писали «км» и «л» сами, разряды то
 * неразрывными пробелами, то обычными). Разряды — `Money::nums`, литры — `Liters`. plain — обычные пробелы для
 * текста в мессенджерах (подпись шеринга), как у сумм там же.
 */
final class Units
{
    /** «72 000 км»; пробег не записан — null (ноль — «0 км»). */
    public static function km(int|float|string|null $km, bool $plain = false): ?string
    {
        if ($km === null || $km === '') return null;
        $text = Money::nums((int) $km).' км';

        return $plain ? str_replace(Money::NBSP, ' ', $text) : $text;
    }

    /** Объём из см³: «1,5 л»; не записан — null. */
    public static function liters(int|float|string|null $cc): ?string
    {
        $l = Liters::format((int) $cc);

        return $l === null ? null : $l.' л';
    }

    /** «150 л. с.»; не записана — null. */
    public static function hp(int|float|string|null $hp): ?string
    {
        return (int) $hp ? (int) $hp.' л. с.' : null;
    }
}
