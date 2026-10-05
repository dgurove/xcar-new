<?php

namespace App\Cars\Vin;

/**
 * VIN в одной записи — у предложения, ТС парковки, писем и поиска одна: верхний регистр, кириллица того же начертания →
 * латиница («ХТА» с русской клавиатуры — тот же XTA), без пробелов и знаков. Маска площадки («XTA21****») — VIN нет.
 */
final class Vin
{
    private const LOOKALIKE = ['А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'К' => 'K', 'М' => 'M', 'О' => '0', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X', 'У' => 'Y', 'З' => '3'];

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null || str_contains($raw, '*')) {
            return null;
        }
        $vin = (string) preg_replace('/[^A-Z0-9]/', '', strtr(mb_strtoupper($raw), self::LOOKALIKE));

        return $vin !== '' ? $vin : null;
    }

    /** Полный VIN — семнадцать знаков; по неполному тождество не ищем. */
    public static function full(?string $raw): ?string
    {
        $vin = self::normalize($raw);

        return $vin !== null && strlen($vin) === 17 ? $vin : null;
    }
}
