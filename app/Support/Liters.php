<?php

namespace App\Support;

/**
 * Объём двигателя: храним в см³ (так его отдают VIN, Carcade и письма), человек видит и вводит литры — «1,5».
 * Ввод понимает и то и другое: меньше 30 — литры, больше — уже см³.
 */
final class Liters
{
    public static function format(?int $cc): ?string
    {
        return $cc ? number_format($cc / 1000, 1, ',', '') : null;
    }

    /** Поле не трогали (показано то же, что было) — остаётся точное значение в см³, а не округлённое до десятых. */
    public static function parse(mixed $value, ?int $current = null): ?int
    {
        $raw = str_replace(',', '.', preg_replace('/[^\d.,]+/u', '', (string) $value) ?? '');
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }
        if ($current && (string) $value === self::format($current)) {
            return $current;
        }
        $n = (float) $raw;

        return $n < 30 ? (int) round($n * 1000) : (int) round($n);
    }
}
