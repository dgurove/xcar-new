<?php

namespace App\Mail\Extraction;

use App\Cars\Names;

/**
 * Поля машины из текста документа во вложении письма — то, что страховая в самом письме не пишет:
 * акт приёма-передачи и договор комиссии (таблица «Марка/модель | XCITE X-CROSS 8», госномер, цвет, год, VIN,
 * «назначает цену … 151 429,00»), оценка с торгов (БитАвто: колонки «Пробег, км  11405  КПП  АКПП»,
 * migtorg: «Год выпуска — 2014», «максимальное предложение … 151 429 руб.»).
 * Подпись и значение — в одной строке через «|», «—», «:» или широкий пробел; значение кончается там же.
 * Марка — только из словаря (`Names`), VIN-заглушка «111…1» — не VIN. Источник — `file`.
 */
final class DocumentFields
{
    private const VIN = '/\b[A-HJ-NPR-Z0-9]{17}\b/u';

    private const PLATE = '/\b[АВЕКМНОРСТУХ]\s?\d{3}\s?[АВЕКМНОРСТУХ]{2}\s?\d{2,3}\b/u';

    private const MONTHS = ['январ' => 1, 'феврал' => 2, 'март' => 3, 'апрел' => 4, 'ма' => 5, 'июн' => 6, 'июл' => 7, 'август' => 8, 'сентябр' => 9, 'октябр' => 10, 'ноябр' => 11, 'декабр' => 12];

    /** @return array<string, array{value: mixed, source: string}> */
    public static function extract(string $text): array
    {
        $text = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}", "\r"], [' ', ' ', ' ', ''], $text);
        if (trim($text) === '') {
            return [];
        }
        $f = [];
        $put = function (string $field, mixed $value) use (&$f) {
            if ($value !== null && $value !== '' && ! isset($f[$field])) {
                $f[$field] = ['value' => $value, 'source' => 'file'];
            }
        };

        foreach (self::values($text, 'Марка\s*\/\s*модель|Марка,\s*модель|Марка\s+и\s+модель|Наименование\s+ТС') as $car) {
            if ($found = Names::find($car)) {
                $put('brand', $found['brand']->name);
                $put('model', $found['model']);
                break;
            }
        }
        foreach (self::values($text, 'Идентификационный\s+номер\s*\(VIN\)|\bVIN\b|\bВИН\b') as $value) {
            if (preg_match(self::VIN, mb_strtoupper($value), $m) && ($vin = CarWords::realVin($m[0]))) {
                $put('vin', $vin);
                break;
            }
        }
        foreach (self::values($text, 'Государственный\s+регистрационный\s+знак|Гос\.?\s*(?:рег\.?\s*)?(?:номер|знак)|\bг\/н\b') as $value) {
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
        foreach (self::values($text, 'Цвет(?:\s+кузова)?') as $value) {
            if (preg_match('/^[\p{L}\- ]{3,30}$/u', $value) && ! preg_match('/нет данных/ui', $value)) {
                $put('color', mb_convert_case(mb_strtolower($value), MB_CASE_TITLE));
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
