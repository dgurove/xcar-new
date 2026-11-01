<?php

namespace App\Offers;

use App\Mail\Extraction\CodeMatcher;
use App\Mail\Extraction\Patterns;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * «Оценить → Из текста» таблицей (25.10.2026, владелец: Альфа присылает оценочные Excel-файлом «16.10-22.xlsx» —
 * номер убытка, ф/ю, дата, «СТАВКА», VIN, филиал, марка). Строка с номером убытка — машина; сумма — столбец, чья шапка
 * про деньги («стоимость», «ставка», «сумма», «оценка», «цена»). Итог — тот же текст «номер сумма», VIN и город строками,
 * что у фото: его разбирает `ValuationText`.
 *
 * Листы — только с такой шапкой (01.11.2026: в «23.10-31.10.xlsx» Альфы рядом с оценками лежал лист «Выплаты по ОР24» —
 * 646 строк «0194/PVU/00163/24 · 1 · 20659» без шапки, и все встали бы в «Нет в CRM» с галкой «завести»); если шапки нет
 * ни у одного листа — сумма строки — первое её число от тысячи, не дата. Сумма столбца — как есть, и 317, и 0, и пустая
 * ячейка (это 0): такие строки окно показывает подозрительными, а не теряет (`MatchValuations`, `suspicious`). В текст —
 * с разрядами и копейками («557 500.00», «0.00»): так их узнаёт `ValuationText::amount`.
 */
final class ValuationSheet
{
    public const EXTENSIONS = ['xlsx', 'xls', 'csv', 'ods'];

    private const MONEY = '/стоим|ставк|сумм|оцен|цен/ui';

    private const CITY = '/город|филиал|регион|место/ui';

    /** @return array{text: string, sure: bool} */
    public static function read(string $path): array
    {
        $matcher = new CodeMatcher;
        // Без стилей: в «23.10-31.10.xlsx» Альфы они весили 7 МБ, а со сводными таблицами чтение шло 8 с и 280 МБ
        // против 0,3 с (01.11.2026). Листы с шапкой суммы стили не нужны — сумма берётся из своего столбца.
        $sheets = array_map(fn ($sheet) => self::sheet($sheet, $matcher, dates: false), self::book($path)->getAllSheets());
        $headed = array_filter($sheets, fn ($s) => $s['headed']);
        if (! $headed) {
            // Шапки нет ни у одного листа: сумма — первое число строки, и дату от суммы отличают только стили — листы с
            // номерами перечитываются целиком.
            $titles = array_values(array_column(array_filter($sheets, fn ($s) => $s['lines']), 'title'));
            $sheets = $titles ? array_map(fn ($sheet) => self::sheet($sheet, $matcher, dates: true), self::book($path, $titles)->getAllSheets()) : [];
        }
        $lines = array_merge([], ...array_column($headed ?: $sheets, 'lines'));

        return ['text' => implode("\n", $lines), 'sure' => $lines !== []];
    }

    /** @param  list<string>|null  $only  листы со стилями (форматы дат); null — все без стилей */
    private static function book(string $path, ?array $only = null): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($path);
        if ($only === null) {
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
        } else {
            $reader->setLoadSheetsOnly($only);
        }

        return $reader->load($path);
    }

    /** @return array{title: string, headed: bool, lines: list<string>} строки «номер сумма», VIN, город; headed — у листа шапка суммы */
    private static function sheet(Worksheet $sheet, CodeMatcher $matcher, bool $dates): array
    {
        $money = $city = null;
        $lines = [];
        $raw = $sheet->toArray(null, true, false, true);
        foreach ($sheet->toArray(null, true, true, true) as $n => $texts) {
            $texts = array_map(fn ($t) => trim((string) $t), $texts);
            $ref = $refCol = null;
            foreach ($texts as $col => $t) {
                if ($t !== '' && ($ref = $matcher->findAll($t)[0] ?? null)) {
                    $refCol = $col;
                    break;
                }
            }
            // Строка без номера — может быть шапкой: запоминаем столбцы суммы и города.
            if ($ref === null) {
                foreach ($texts as $col => $t) {
                    $money ??= preg_match(self::MONEY, $t) ? $col : null;
                    $city ??= preg_match(self::CITY, $t) ? $col : null;
                }

                continue;
            }
            $number = fn ($col) => self::number($raw[$n][$col] ?? null, fn () => $dates && Date::isDateTime($sheet->getCell($col.$n)));
            if ($money !== null) {
                $amount = $number($money) ?? 0;
            } else {
                $amount = null;
                // Не из ячейки номера: «5691/046/00885/26» иначе давал сумму 5 691.
                foreach (array_diff(array_keys($texts), [$refCol]) as $col) {
                    if (($v = $number($col)) !== null && $v >= 1000) {
                        $amount = $v;
                        break;
                    }
                }
            }
            $lines[] = trim($ref.' '.($amount === null ? '' : number_format($amount, 2, '.', ' ')));
            ($vin = Patterns::vins(implode(' ', $texts))[0] ?? null) && $lines[] = $vin;
            $city !== null && ($texts[$city] ?? '') !== '' && $lines[] = $texts[$city];
        }

        return ['title' => $sheet->getTitle(), 'headed' => $money !== null, 'lines' => $lines];
    }

    /** Целые рубли из ячейки: число не датой (0 — тоже сумма) или текст «635 237,00». */
    private static function number(mixed $value, \Closure $isDate): ?int
    {
        if (is_int($value) || is_float($value)) {
            return $value < 0 || $isDate() ? null : (int) round($value);
        }

        return ValuationText::amount((string) $value);
    }
}
