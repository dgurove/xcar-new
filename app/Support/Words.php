<?php

namespace App\Support;

/** Сумма прописью для договора: «восемьсот пятьдесят пять тысяч». */
final class Words
{
    private const ONES = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять', 'десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];

    private const ONES_F = ['', 'одна', 'две'];

    private const TENS = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];

    private const HUNDREDS = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];

    public static function rub(int|float $amount): string
    {
        $n = (int) round($amount);
        if ($n === 0) {
            return 'ноль';
        }
        $parts = [];
        $groups = [[1_000_000_000, ['миллиард', 'миллиарда', 'миллиардов'], false], [1_000_000, ['миллион', 'миллиона', 'миллионов'], false], [1000, ['тысяча', 'тысячи', 'тысяч'], true], [1, null, false]];
        foreach ($groups as [$div, $forms, $female]) {
            $g = intdiv($n, $div);
            $n %= $div;
            if ($g === 0) {
                continue;
            }
            $parts[] = self::triple($g, $female).($forms ? ' '.Plural::of($g, $forms) : '');
        }

        return trim(implode(' ', $parts));
    }

    private static function triple(int $n, bool $female): string
    {
        $words = [self::HUNDREDS[intdiv($n, 100)]];
        $rest = $n % 100;
        if ($rest < 20) {
            $words[] = $female && $rest > 0 && $rest < 3 ? self::ONES_F[$rest] : self::ONES[$rest];
        } else {
            $words[] = self::TENS[intdiv($rest, 10)];
            $u = $rest % 10;
            $words[] = $female && $u > 0 && $u < 3 ? self::ONES_F[$u] : self::ONES[$u];
        }

        return trim(implode(' ', array_filter($words)));
    }
}
