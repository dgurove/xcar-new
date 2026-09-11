<?php

namespace App\Support;

final class Phone
{
    /** «+7 (900) 123-45-67», «8 900 1234567», «9001234567» → «79001234567». Не телефон → null. */
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);

        if (strlen($digits) === 10) {
            $digits = '7'.$digits;
        } elseif (strlen($digits) === 11 && $digits[0] === '8') {
            $digits[0] = '7';
        }

        return preg_match('/^7\d{10}$/', $digits) ? $digits : null;
    }

    public static function format(?string $normalized): string
    {
        if (! $normalized || strlen($normalized) !== 11) {
            return (string) $normalized;
        }

        return sprintf('+7 %s %s-%s-%s',
            substr($normalized, 1, 3), substr($normalized, 4, 3), substr($normalized, 7, 2), substr($normalized, 9, 2));
    }

    public static function looksLikePhone(string $login): bool
    {
        return (bool) preg_match('/^[\d\s()+\-]{7,}$/', trim($login));
    }
}
