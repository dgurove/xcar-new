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
    public static function rub(int|float|string|null $value, int $decimals = 0): string
    {
        return $value === null || $value === '' ? '' : self::nums($value, $decimals).self::NBSP.'₽';
    }

    /**
     * Целые рубли из набранного — одна дверь для всех форм (16.10.2026: шестнадцать мест разбирали сумму сами, и
     * «150 000,50» после NormalizeNumbers («150000.50») становилось 15 000 050). Копейки отбрасываются: запятая или точка
     * не перед тройкой цифр — дробная часть («1.150.000» — разряды точками). Цифр нет — null.
     */
    public static function rubles(int|float|string|null $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (! is_string($value)) return (int) $value;
        $digits = preg_replace('/\D+/', '', preg_split('/,|\.(?!\d{3}(?:\D|$))/', $value)[0]);

        return $digits === '' ? null : (int) $digits;
    }

    /** «15 000,50», «15000.5», «15 000 ₽» — одно число: люди пишут суммы как привыкли. */
    public static function parse(?string $value): string
    {
        return str_replace(',', '.', preg_replace('/[^\d.,]/u', '', (string) $value));
    }

    /** Копейки — только когда они есть: «15 000,50 ₽» и «15 000 ₽». Округлять чужие траты нельзя. */
    public static function exact(int|float|string|null $value): string
    {
        return $value === null || $value === '' ? '' : self::rub($value, fmod((float) $value, 1) === 0.0 ? 0 : 2);
    }
}
