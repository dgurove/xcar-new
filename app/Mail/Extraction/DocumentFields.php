<?php

namespace App\Mail\Extraction;

use App\Cars\Colors;
use App\Cars\Vin\VinText;

/**
 * Поля машины из текста документа во вложении письма — то, что страховая в самом письме не пишет:
 * акт приёма-передачи и договор комиссии (таблица «Марка/модель | XCITE X-CROSS 8», госномер, цвет, год, VIN,
 * «назначает цену … 151 429,00»), оценка с торгов (БитАвто: колонки «Пробег, км  11405  КПП  АКПП»,
 * migtorg: «Год выпуска — 2014», «максимальное предложение … 151 429 руб.»).
 * Подпись и значение — в одной строке через «|», «—», «:» или широкий пробел; значение кончается там же;
 * таблица столбцами — по строке с VIN (`carRow`). Текст часто из OCR скана: VIN — `VinText` (контрольная цифра),
 * марка и модель — `ScanCar` (словарь, латиница вместо кириллицы, опечатки, VIN). VIN-заглушка «111…1» — не VIN.
 * Источник — `file`.
 */
final class DocumentFields
{
    private const VIN = '/\b[A-HJ-NPR-Z0-9]{17}\b/u';

    private const PLATE = '/\b[АВЕКМНОРСТУХ]\s?\d{3}\s?[АВЕКМНОРСТУХ]{2}\s?\d{2,3}\b/u';

    private const MONTHS = ['январ' => 1, 'феврал' => 2, 'март' => 3, 'апрел' => 4, 'ма' => 5, 'июн' => 6, 'июл' => 7, 'август' => 8, 'сентябр' => 9, 'октябр' => 10, 'ноябр' => 11, 'декабр' => 12];

    /** @return array<string, array{value: mixed, source: string}> */
    public static function extract(string $text): array
    {
        $text = self::cyrillic(str_replace(["\u{00A0}", "\u{2007}", "\u{202F}", "\r"], [' ', ' ', ' ', ''], $text));
        if (trim($text) === '') {
            return [];
        }
        $f = [];
        $put = function (string $field, mixed $value) use (&$f) {
            if ($value !== null && $value !== '' && ! isset($f[$field])) {
                $f[$field] = ['value' => $value, 'source' => 'file'];
            }
        };

        foreach (self::values($text, 'Идентификационный\s+номер(?:\s*\(VIN\))?|\bVIN(?:\s+ТС)?\b|\bВИН\b') as $value) {
            if (preg_match(self::VIN, mb_strtoupper($value), $m) && ($vin = VinText::labelled($m[0]))) {
                $put('vin', $vin);
                break;
            }
            if ($place = VinText::locate($value)) {
                $put('vin', $place['vin']);
                if ($place['vin']) {
                    break;
                }
            }
        }
        // Марка с моделью одной строкой (акт, договор), иначе «Марка» и «Модель» порознь (акт Альфы, ЭПТС: «Марка HONDA»,
        // «Коммерческое наименование CR-V» или «GEELY EX5 EM-i» — с маркой внутри).
        foreach (self::values($text, 'Марка\s*\/\s*модель|Марка,\s*модель|Марка\s+и\s+модель|Наименование\s+ТС') as $car) {
            if ($found = ScanCar::of($car)) {
                $put('brand', $found['brand']);
                $put('model', $found['model']);
                break;
            }
        }
        self::carRow($text, $f, $put);
        if (! isset($f['brand']) && ($mark = self::values($text, 'Марка(?:\s+ТС)?')[0] ?? null)) {
            $model = self::values($text, 'Модель(?:,\s*модификация)?(?:\s+ТС)?|Коммерческое\s+наименование')[0] ?? '';
            if ($found = ScanCar::of(str_starts_with(mb_strtolower($model), mb_strtolower($mark)) ? $model : $mark.' '.$model)) {
                $put('brand', $found['brand']);
                $put('model', $found['model']);
            }
        }
        // Марку так и не прочли — по VIN: память базы или декодер (`ScanCar::carOfVin`).
        if (! isset($f['brand']) && isset($f['vin']) && ($car = ScanCar::carOfVin($f['vin']['value']))) {
            $put('brand', $car['brand']->name);
            $put('model', $car['model']);
        }
        foreach (self::values($text, 'Государственный\s+регистрационный\s+знак|Регистрац\w*\.?\s*знак|Гос\.?\s*(?:рег\.?\s*)?(?:номер|знак)|\bг\/н\b') as $value) {
            if (preg_match(self::PLATE, mb_strtoupper($value), $m)) {
                $put('plate', (string) preg_replace('/\s+/u', '', $m[0]));
                break;
            }
        }
        foreach (self::values($text, 'Год\s+выпуска|Год\s+изготовления') as $value) {
            if (preg_match('/\b(19[89]\d|20[0-3]\d)\b/u', $value, $m)) {
                $put('year', (int) $m[1]);
                break;
            }
        }
        foreach (self::values($text, 'Цвет(?:\s+(?:кузова|автомобиля))?') as $value) {
            // Цвет — по словарю (`Colors`): «автомобиля», «Год» — соседние подписи шапки, «Черый» — опечатка OCR.
            if ($color = Colors::normalize($value)) {
                $put('color', $color);
                break;
            }
        }
        foreach (self::values($text, 'Пробег(?:,?\s*км)?') as $value) {
            if (($km = CarWords::digits($value)) && $km < 3_000_000) {
                $put('mileage', $km);
                break;
            }
        }
        $put('transmission', CarWords::transmission(self::values($text, 'КПП|Коробка\s+передач|Тип\s+КПП')[0] ?? null));
        $put('drive', CarWords::drive(self::values($text, 'Привод')[0] ?? null));
        $put('fuel', CarWords::fuel(self::values($text, 'Тип\s+двигателя|Вид\s+топлива|Топливо')[0] ?? null));
        $put('engine_volume', CarWords::cc(self::values($text, 'V\s*объ[её]м,?\s*л\.?|Объ[её]м\s+двигателя(?:,\s*(?:л|см3|куб\.?\s*см)\.?)?')[0] ?? null));
        $power = CarWords::digits(self::values($text, 'Мощность(?:\s+двигателя)?(?:,\s*л\.?\s*с\.?)?')[0] ?? null);
        $put('engine_power', $power && $power < 2000 ? $power : null);
        $city = self::values($text, '\bГород')[0] ?? null;
        $put('city', $city ? trim((string) preg_replace('/^г\.?\s+/u', '', $city)) : null);
        $put('floor_price', self::price($text));
        $put('offer_until', self::until($text));

        return $f;
    }

    /**
     * Таблица столбцами — «Заявка на Приёмку/Выдачу» Альфы: шапка «Марка ТС | Модель | VIN ТС | Цвет | Год выпуска»,
     * под ней строка значений. Строка с VIN и есть строка машины: слева марка с моделью, справа цвет и год. Документ
     * с разными VIN (реестр, акт по нескольким ТС) — не про одну машину, строку не берём.
     */
    private static function carRow(string $text, array $f, \Closure $put): void
    {
        $lines = preg_split('/\n/u', $text) ?: [];
        $rows = [];
        foreach ($lines as $n => $line) {
            if ($place = VinText::locate($line)) {
                $rows[] = $place + ['line' => $n];
            }
        }
        if (! $rows || count(array_unique(array_filter(array_column($rows, 'vin')))) > 1) {
            return;
        }
        // Строка с прочитанным VIN; VIN не прочёлся — та «почти VIN», слева от которой машина: шапка «Марка ТС
        // модификация VIN ТС Цвет» тоже похожа на строку с VIN, но марки в ней нет.
        $vins = array_values(array_filter($rows, fn ($r) => $r['vin']));
        $row = $vins[0] ?? collect($rows)->first(fn ($r) => ScanCar::of($r['before'])) ?? $rows[0];
        $put('vin', $row['vin']);
        $after = $row['after'];
        if (! isset($f['brand'])) {
            // Слева от VIN; пусто — строка над ним: акт, где OCR потерял подписи серых ячеек («EXEED VX» строкой выше),
            // или заявка, где VIN в ячейке опустился ниже марки («FORLAND 27772A белый 2024 …», VIN строкой ниже),
            // или под ним, где ниже опустилась марка («омода S5 стр. 10»). Марка с моделью — первые слова той строки,
            // цвет и год — из её остатка.
            $found = ScanCar::of($row['before']);
            foreach ([-1, -2, -3, 1, 2] as $step) {
                if ($found || trim($row['before'], " \t|") !== '' || ! isset($lines[$row['line'] + $step])) {
                    continue;
                }
                $words = preg_split('/\s+/u', trim($lines[$row['line'] + $step]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($words && ($found = ScanCar::of(implode(' ', array_slice($words, 0, 3))))) {
                    $after .= ' '.implode(' ', array_slice($words, 1));
                }
            }
            if ($found) {
                $put('brand', $found['brand']);
                $put('model', $found['model']);
            }
        }
        // Цвет — первое слово справа от VIN, которое знает словарь (с соседним «металлик», «перламутр»).
        $words = preg_split('/[\s|]+/u', trim($after), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $i => $word) {
            if (preg_match('/^[А-Яа-яЁё\-]{3,}$/u', $word) && ($color = Colors::normalize($word.' '.($words[$i + 1] ?? '')) ?? Colors::normalize($word))) {
                $put('color', $color);
                break;
            }
        }
        if (preg_match('/(?<![\d.,\/])(19[89]\d|20[0-3]\d)(?![\d.,\/])/u', $after, $m)) {
            $put('year', (int) $m[1]);
        }
        // Стоимость столбцом заявки Альфы «1.477.000,00» или «1 477 000,00»; парковке её подставляет только человек в «✨».
        // Сумма — с точками-тысячами или с копейками: «8 903 728 61 57» и «996 490 34 15» — телефоны, не цена.
        if (preg_match('/(?<![\d.,])(\d{1,3}(?:\.\d{3}){1,3}(?:,\d{2})?|\d{1,3}(?: \d{3}){1,3},\d{2})(?![\d.])/u', $after, $m) && ($n = (int) preg_replace('/\D/', '', preg_replace('/,\d{2}$/', '', $m[1]))) >= 10_000) {
            $put('value', $n);
        }
    }

    /**
     * OCR путает кириллицу с похожей латиницей и греческим: «Мapka», «cтp.», «ΠTC», номер «X 559 CB 797». Слово, где
     * кириллица смешана с чужими буквами, и номер по форме — в кириллицу; чисто латинские слова (марка, VIN) не трогаем.
     */
    private static function cyrillic(string $text): string
    {
        $map = ['A' => 'А', 'a' => 'а', 'B' => 'В', 'C' => 'С', 'c' => 'с', 'E' => 'Е', 'e' => 'е', 'H' => 'Н', 'K' => 'К', 'k' => 'к', 'M' => 'М', 'O' => 'О', 'o' => 'о', 'P' => 'Р', 'p' => 'р', 'T' => 'Т', 'X' => 'Х', 'x' => 'х', 'Y' => 'У', 'y' => 'у', 'Π' => 'П', 'Φ' => 'Ф', 'Λ' => 'Л', 'Γ' => 'Г', 'Δ' => 'Д', 'Ω' => 'О', 'Ρ' => 'Р', 'Τ' => 'Т', 'Ν' => 'Н', 'Μ' => 'М', 'Κ' => 'К', 'Α' => 'А', 'Β' => 'В', 'Ε' => 'Е', 'Χ' => 'Х', 'Ο' => 'О'];
        $text = (string) preg_replace_callback('/[\p{L}]+/u', fn ($m) => preg_match('/\p{Cyrillic}/u', $m[0]) && preg_match('/[^\p{Cyrillic}]/u', $m[0]) ? strtr($m[0], $map) : $m[0], $text);

        return (string) preg_replace_callback('/\b[ABEKMHOPCTYX]\s?\d{3}\s?[ABEKMHOPCTYX]{2}\s?\d{2,3}\b/u', fn ($m) => strtr($m[0], $map), $text);
    }

    /**
     * Значения после подписи: «Подпись | значение», «Подпись — значение», «Подпись: значение», колонки через
     * два пробела и больше. Значение — до конца ячейки, следующей колонки или строки.
     *
     * @return list<string>
     */
    private static function values(string $text, string $label): array
    {
        preg_match_all('/(?:^|[|\s])(?:'.$label.')[ \t]*(?:[:|—–][ \t]*|[ \t]{2,}|[ \t])([^|\n]+?)(?=[ \t]{2,}|[ \t]*\||[ \t]*\n|$)/umi', $text, $m);

        return array_values(array_filter(array_map(fn ($v) => trim($v, " \t:—–-"), $m[1]), fn ($v) => $v !== ''));
    }

    /** Цена: «назначает цену … 151 429,00 (сто …)», «Максимальная ставка: 1259990 руб.», «максимальное предложение … 151 429 руб.». */
    private static function price(string $text): ?int
    {
        foreach ([
            '/назначает\s+цену[^0-9]{0,80}?(\d[\d ]{2,})(?:,\d{2})?/ui',
            '/максимальн(?:ая\s+ставка|ое\s+предложение)[^0-9]{0,120}?(\d[\d ]{3,})\s*(?:,\d{2})?\s*(?:руб|р\.|₽)/uis',
        ] as $pattern) {
            if (preg_match($pattern, $text, $m) && ($n = CarWords::digits($m[1])) && $n >= 1000) {
                return $n;
            }
        }

        return null;
    }

    /** Покупатель держит цену до: «Срок действия предложения до 14.10.2026», «… до 11 октября 2026». */
    private static function until(string $text): ?string
    {
        if (! preg_match('/срок\s+действия\s+предложения[^0-9]{0,80}?до\s+(\d{1,2})[.\s]+(\d{1,2}|[а-я]+)[.\s]+(\d{4})/ui', $text, $m)) {
            return null;
        }
        $month = ctype_digit($m[2]) ? (int) $m[2] : null;
        foreach (self::MONTHS as $stem => $n) {
            if ($month === null && str_starts_with(mb_strtolower($m[2]), $stem)) {
                $month = $n;
            }
        }

        return $month && checkdate($month, (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $month, $m[1]) : null;
    }
}
