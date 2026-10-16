<?php

namespace App\Support;

/**
 * Количество, множитель или цена в поле — без лишних нулей: «3», «1.5», «0.25» (16.10.2026: одиннадцать мест писали
 * `rtrim(rtrim(number_format(…)))` сами). Два знака после точки, хвостовые нули и точка срезаются. point — знак дроби
 * (в таблице для Excel — запятая), thousands — разряды (в печатном счёте — пробел).
 */
final class Qty
{
    public static function format(int|float|string $value, string $point = '.', string $thousands = ''): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, $point, $thousands), '0'), $point);
    }
}
