<?php

namespace App\Mail\Extraction;

use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Transmission;

/**
 * Слова о машине в письмах и документах → значения справочников: «АКПП» — automatic, «полный» — awd, «Бензин» — petrol.
 * Одно место на шаблоны писем (Совкомбанк) и на вложения (`DocumentFields`: оценка с торгов, акт, договор комиссии).
 */
final class CarWords
{
    private const FUEL_WORDS = ['бензин' => 'petrol', 'дизел' => 'diesel', 'гибрид' => 'hybrid', 'электр' => 'electric', 'газ' => 'gas'];

    /** Первое число в строке: «1998 см3» — 1998, а не 19983; «1 259 990 руб.» — 1259990. */
    public static function digits(?string $value): ?int
    {
        if ($value === null || ! preg_match('/\d[\d\s\x{00A0}\x{2007}\x{202F}]*/u', $value, $m)) {
            return null;
        }
        $digits = preg_replace('/\D/u', '', $m[0]) ?? '';

        return $digits !== '' && (int) $digits > 0 ? (int) $digits : null;
    }

    /** Объём в см³: «1.6» и «1,6 л» — литры, «1598» — уже см³. */
    public static function cc(?string $value): ?int
    {
        if ($value === null || ! preg_match('/\d+(?:[.,]\d+)?/u', $value, $m)) {
            return null;
        }
        $n = (float) str_replace(',', '.', $m[0]);

        return match (true) {
            $n <= 0 => null,
            $n < 30 => (int) round($n * 1000),
            default => (int) $n,
        };
    }

    public static function fuel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $word = mb_strtolower($value);
        if (str_contains($word, 'бензин') && str_contains($word, 'газ')) {
            return 'gas';
        }
        foreach (self::FUEL_WORDS as $needle => $enum) {
            if (str_contains($word, $needle)) {
                return Fuel::tryFrom($enum)?->value;
            }
        }

        return null;
    }

    public static function transmission(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $word = mb_strtolower($value);
        $mapped = match (true) {
            str_contains($word, 'акпп') || str_contains($word, 'автомат') => 'automatic',
            str_contains($word, 'мкпп') || str_contains($word, 'механ') => 'manual',
            str_contains($word, 'вариатор') => 'cvt',
            str_contains($word, 'робот') => 'dual_clutch',
            default => null,
        };

        return $mapped ? Transmission::tryFrom($mapped)?->value : null;
    }

    public static function drive(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $word = mb_strtolower($value);
        $mapped = match (true) {
            str_contains($word, 'полн') || preg_match('/4\s*[хx]\s*4|\bawd\b|\b4\s*wd\b/u', $word) === 1 => 'awd',
            str_contains($word, 'передн') || preg_match('/\bf\s*wd\b/u', $word) === 1 => 'fwd',
            str_contains($word, 'задн') || preg_match('/4\s*[хx]\s*2|\brwd\b/u', $word) === 1 => 'rwd',
            default => null,
        };

        return $mapped ? Drive::tryFrom($mapped)?->value : null;
    }

    /** VIN-заглушка из одинаковых знаков («11111111111111111» в оценке торгов) — не VIN. */
    public static function realVin(?string $vin): ?string
    {
        $vin = $vin === null ? null : strtoupper(trim($vin));

        return $vin !== null && strlen($vin) === 17 && strlen(count_chars($vin, 3)) > 1 ? $vin : null;
    }
}
