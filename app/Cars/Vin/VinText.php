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

    /** Буквы и цифры, которые OCR путает: VIN здесь, модель скана — `ScanCar`. */
    public const SLIPS = ['8' => 'B', 'B' => '8', '5' => 'S', 'S' => '5', '2' => 'Z', 'Z' => '2', '6' => 'G', 'G' => '6', '0' => 'D', 'D' => '0', '1' => 'L', 'L' => '1', '4' => 'A', 'A' => '4', '7' => 'T', 'T' => '7'];

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
                $norm = self::unlabel(self::normalize(implode('', array_column($part, 0))));
                if (strlen($norm) > 18) {
                    break;
                }
                if (strlen($norm) < 16 || ! preg_match('/^[A-HJ-NPR-Z0-9]+$/', $norm) || preg_match_all('/\d/', $norm) < 5 || ! preg_match('/[A-Z]/', $norm)) {
                    continue;
                }
                $last = end($part);
                $vin = match (strlen($norm)) {
                    17 => self::trusted($norm),
                    18 => self::extra($norm),
                    default => null,
                };
                $place = ['vin' => $vin, 'before' => substr($line, 0, $part[0][1]), 'after' => substr($line, $last[1] + strlen($last[0]))];
                if (strlen($norm) === 17 || $vin) {
                    return $place;
                }
                $near ??= $place;
            }
        }

        return $near;
    }

    /**
     * VIN, которому можно верить, или null. Похож на VIN (`plausible`) и WMI известен — по справочнику или по нашей
     * базе (`KnownWmi`: «EDE…» у Chery и Tenet в справочнике нет). Китай и Северная Америка контрольную цифру ставят всегда — она обязана сойтись, хоть после одной
     * правки типичной ошибки OCR (8↔B, 5↔S…), и правка должна быть единственной. Остальные (Россия, Европа, Корея,
     * Беларусь) её часто не считают: им хватает известного WMI.
     */
    public static function trusted(string $vin): ?string
    {
        if (! self::plausible($vin)) {
            return null;
        }
        if (self::knownWmi($vin) && VinDecoder::checkDigitOk($vin)) {
            return $vin;
        }
        // Ошибка бывает и в самом WMI («LSSA…» вместо «LS5A…» у Changan): правка проверяется целиком.

        $fixed = [];
        foreach (str_split($vin) as $i => $char) {
            if (isset(self::SLIPS[$char])) {
                $try = substr_replace($vin, self::SLIPS[$char], $i, 1);
                if (self::plausible($try) && VinDecoder::expectedCheckChar($try) === $try[8] && self::knownWmi($try)) {
                    $fixed[$try] = true;
                }
            }
        }

        return count($fixed) === 1 ? (string) array_key_first($fixed) : null;
    }

    /**
     * VIN после подписи («VIN», «Идентификационный номер»): подпись — сильное свидетельство, поэтому хватает формы VIN
     * (`plausible`) и без известного WMI — так проходят новые российские площадки. Китай и Северная Америка — всё
     * равно с контрольной цифрой. VIN-заглушка «11111111111111111» и слова документа формы не проходят.
     */
    public static function labelled(string $vin): ?string
    {
        $vin = strtoupper(trim($vin));
        if ($trusted = self::trusted($vin)) {
            return $trusted;
        }

        return self::plausible($vin) && VinDecoder::checkDigitOk($vin) ? $vin : null;
    }

    /**
     * Похоже ли на VIN без справочника: 17 допустимых знаков, не меньше 4 разных и 5 цифр, последние четыре — цифры
     * (номер кузова; так у всех VIN нашей базы). Слова документа, прошедшие OCR как «VIN» — «HA001NGAUT0M0B1LE»
     * («налог автомобиле»), «HACT0E00C0E0TMETK», — этим отсекаются.
     */
    public static function plausible(string $vin): bool
    {
        return preg_match('/^[A-HJ-NPR-Z0-9]{13}\d{4}$/', $vin) === 1 && strlen(count_chars($vin, 3)) >= 4 && preg_match_all('/\d/', $vin) >= 5;
    }

    /**
     * Один VIN, прочитанный по-разному: отличаются только знаками, которые OCR путает (`SLIPS`): «XZGEE04A5RA…» и
     * «XZGEE04ASRA…». Тогда верен тот, что сходится по контрольной цифре, иначе — с цифрой на спорном месте.
     */
    public static function better(string $a, string $b): ?string
    {
        if (strlen($a) !== 17 || strlen($b) !== 17 || $a === $b) {
            return $a === $b ? $a : null;
        }
        foreach (str_split($a) as $i => $char) {
            if ($char !== $b[$i] && (self::SLIPS[$char] ?? null) !== $b[$i]) {
                return null;
            }
        }
        $check = fn (string $v) => VinDecoder::expectedCheckChar($v) === $v[8];
        if ($check($a) !== $check($b)) {
            return $check($a) ? $a : $b;
        }

        return preg_match_all('/\d/', $a) >= preg_match_all('/\d/', $b) ? $a : $b;
    }

    /**
     * 18 знаков — OCR вставил лишний («LVWVDB21B7PD983002»): выкинуть по одному, верен единственный вариант, которому
     * можно верить и у которого сошлась контрольная цифра (без неё любой из вариантов годился бы).
     */
    private static function extra(string $run): ?string
    {
        $found = [];
        for ($i = 0; $i < 18; $i++) {
            $try = substr($run, 0, $i).substr($run, $i + 1);
            if (self::plausible($try) && self::knownWmi($try) && VinDecoder::expectedCheckChar($try) === $try[8]) {
                $found[$try] = true;
            }
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /** Подпись, приклеенная к VIN («HOMepZ8NTAAT32ES130848» — «номер» латиницей): отрезать, если остаётся 17 знаков. */
    private static function unlabel(string $norm): string
    {
        if (strlen($norm) > 17 && preg_match('/^(?:H0MEP|N0MEP|N0MER|V1N)([A-HJ-NPR-Z0-9]{17})$/', $norm, $m)) {
            return $m[1];
        }

        return $norm;
    }

    private static function normalize(string $raw): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtr(mb_strtoupper($raw), self::LOOKALIKE));
    }

    private static function knownWmi(string $vin): bool
    {
        return KnownWmi::has($vin);
    }
}
