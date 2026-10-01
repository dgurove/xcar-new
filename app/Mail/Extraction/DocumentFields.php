<?php

namespace App\Mail\Extraction;

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

        foreach (self::values($text, 'Идентификационный\s+номер(?:\s*\(VIN\))?|\bVIN(?:\s+ТС)?\b|\bВИН\b') as $value) {
            if (preg_match(self::VIN, mb_strtoupper($value), $m) && ($vin = CarWords::realVin($m[0]))) {
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
        // Марку так и не прочли — по VIN, если декодер в ней уверен (модели VIN не даёт).
        if (! isset($f['brand']) && isset($f['vin']) && ($brand = ScanCar::brandOfVin($f['vin']['value']))) {
            $put('brand', $brand->name);
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
        foreach (self::values($text, 'Цвет(?:\s+(?:кузова|автомобиля))?') as $value) {
            // Цвет — прилагательным («белый», «серый металлик»); «автомобиля», «Год» — соседние подписи шапки.
            if (preg_match('/^[\p{L}\-]+(?:ый|ий|ой|ая)(?:[\p{L}\- ]{0,20})$/u', $value) && ! preg_match('/нет данных/ui', $value)) {
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
        $vins = array_values(array_filter($rows, fn ($r) => $r['vin']));
        $row = $vins[0] ?? $rows[0];
        $put('vin', $row['vin']);
        if (! isset($f['brand'])) {
            // Слева от VIN; нет — короткая строка над ним (акт, где OCR потерял подписи серых ячеек: «EXEED VX» строкой выше).
            $found = ScanCar::of($row['before']);
            for ($up = 1; ! $found && $up <= 3 && isset($lines[$row['line'] - $up]); $up++) {
                $above = trim($lines[$row['line'] - $up]);
                $found = $above !== '' && count(preg_split('/\s+/u', $above)) <= 4 ? ScanCar::of($above) : null;
            }
            if ($found) {
                $put('brand', $found['brand']);
                $put('model', $found['model']);
            }
        }
        if (preg_match('/^[\s|]*([А-Яа-яЁё]{3,}(?:ый|ий|ой|ая))\b/u', $row['after'], $m)) {
            $put('color', mb_convert_case(mb_strtolower($m[1]), MB_CASE_TITLE));
        }
        if (preg_match('/(?<![\d.,])(19[89]\d|20[0-3]\d)(?![\d.,])/u', $row['after'], $m)) {
            $put('year', (int) $m[1]);
        }
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
