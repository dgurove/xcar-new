<?php

namespace App\Support;

/**
 * Число с разрядами для экрана. Разряды и знак рубля держатся неразрывными
 * пробелами: цена или пробег переносятся на другую строку только целиком —
 * «4 170 000 ₽» не разрывается на «4 170» и «000 ₽». Для текста уведомлений и
 * подписей в мессенджерах — обычный number_format с пробелом.
 */
final class Money
{
    public const NBSP = "\u{00A0}";

    /** Разряды неразрывными пробелами: 4170000 → «4 170 000». */
    public static function nums(int|float|string|null $value, int $decimals = 0): string
    {
        return $value === null || $value === '' ? '' : number_format((float) $value, $decimals, ',', self::NBSP);
    }

    /** То же со знаком рубля: «4 170 000 ₽». */
    public static function rub(int|float|string|null $value): string
    {
        return $value === null || $value === '' ? '' : self::nums($value).self::NBSP.'₽';
    }
}
