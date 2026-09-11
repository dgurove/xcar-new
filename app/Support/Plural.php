<?php

namespace App\Support;

/** Склонение по числу: Plural::of(5, ['предложение', 'предложения', 'предложений']). */
final class Plural
{
    public static function of(int $n, array $forms): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $forms[2];
        }
        if ($n1 > 1 && $n1 < 5) {
            return $forms[1];
        }
        if ($n1 === 1) {
            return $forms[0];
        }

        return $forms[2];
    }
}
