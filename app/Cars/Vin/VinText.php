<?php

namespace App\Cars\Vin;

/**
 * VIN в строке скана: OCR рвёт его пробелом («XZGFFO5A8RA803 142»), ставит O вместо нуля и кириллицу вместо
 * латиницы. `locate()` находит место VIN в строке таблицы — слева марка и модель, справа цвет и год; сам VIN
 * отдаёт, только если ему можно верить (`trusted`).
 */
final class VinText
{
    private const LOOKALIKE = ['А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'К' => 'K', 'М' => 'M', 'О' => '0', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X', 'У' => 'Y', 'З' => '3', 'O' => '0', 'I' => '1', 'Q' => '0'];

    private const SLIPS = ['8' => 'B', 'B' => '8', '5' => 'S', 'S' => '5', '2' => 'Z', 'Z' => '2', '6' => 'G', 'G' => '6', '0' => 'D', 'D' => '0', '1' => 'L', 'L' => '1', '4' => 'A', 'A' => '4', '7' => 'T', 'T' => '7'];

    /**
     * Строка таблицы с VIN: что слева, что справа и сам VIN, если ему можно верить. Номер из 16–18 знаков —
     * тоже место VIN (OCR потерял или добавил знак): строку машины он обозначает, хоть VIN из него и не взять.
     *
     * @return array{vin: ?string, before: string, after: string}|null
     */
    public static function locate(string $line): ?array
    {
        preg_match_all('/[^\s|]+/u', $line, $m, PREG_OFFSET_CAPTURE);
        $tokens = $m[0];
        $near = null;
        foreach (array_keys($tokens) as $i) {
            for ($span = 1; $span <= 3 && isset($tokens[$i + $span - 1]); $span++) {
                $part = array_slice($tokens, $i, $span);
                // Куски VIN — буквы и цифры без знаков, первый длинный: «Е240020/06/03/22 от «16»» VIN не склеит.
                if (! preg_match('/^[\p{L}\d]+$/u', $part[$span - 1][0]) || mb_strlen($part[0][0]) < 6) {
                    break;
                }
                $norm = self::normalize(implode('', array_column($part, 0)));
                if (strlen($norm) > 18) {
                    break;
                }
                if (strlen($norm) < 16 || ! preg_match('/^[A-HJ-NPR-Z0-9]+$/', $norm) || preg_match_all('/\d/', $norm) < 5 || ! preg_match('/[A-Z]/', $norm)) {
                    continue;
                }
                $last = end($part);
                $place = ['vin' => strlen($norm) === 17 ? self::trusted($norm) : null, 'before' => substr($line, 0, $part[0][1]), 'after' => substr($line, $last[1] + strlen($last[0]))];
                if (strlen($norm) === 17) {
                    return $place;
                }
                $near ??= $place;
            }
        }

        return $near;
    }

    /**
     * VIN, которому можно верить, или null. WMI должен быть известен (иначе «EDEDB21B8…» с совпавшей случайно цифрой
     * прошёл бы). Китай и Северная Америка контрольную цифру ставят всегда — она обязана сойтись, хоть после одной
     * правки типичной ошибки OCR (8↔B, 5↔S…), и правка должна быть единственной. Остальные (Россия, Европа, Корея,
     * Беларусь) её часто не считают: им хватает известного WMI.
     */
    public static function trusted(string $vin): ?string
    {
        if (! preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin) || strlen(count_chars($vin, 3)) < 4) {
            return null;
        }
        if (self::knownWmi($vin) && (VinDecoder::expectedCheckChar($vin) === $vin[8] || ! preg_match('/^[1-5L]/', $vin))) {
            return $vin;
        }
        // Ошибка бывает и в самом WMI («LSSA…» вместо «LS5A…» у Changan): правка проверяется целиком.

        $fixed = [];
        foreach (str_split($vin) as $i => $char) {
            if (isset(self::SLIPS[$char])) {
                $try = substr_replace($vin, self::SLIPS[$char], $i, 1);
                if (VinDecoder::expectedCheckChar($try) === $try[8] && self::knownWmi($try)) {
                    $fixed[$try] = true;
                }
            }
        }

        return count($fixed) === 1 ? (string) array_key_first($fixed) : null;
    }

    private static function normalize(string $raw): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtr(mb_strtoupper($raw), self::LOOKALIKE));
    }

    private static function knownWmi(string $vin): bool
    {
        static $wmi = null;
        $wmi ??= (new VinTables)->wmi();

        return isset($wmi[substr($vin, 0, 3)]);
    }
}
