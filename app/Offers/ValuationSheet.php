<?php

namespace App\Offers;

use App\Mail\Extraction\CodeMatcher;
use App\Mail\Extraction\Patterns;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * «Оценить → Из текста» таблицей (25.10.2026, владелец: Альфа присылает оценочные Excel-файлом «16.10-22.xlsx» —
 * номер убытка, ф/ю, дата, «СТАВКА», VIN, филиал, марка). Строка с номером убытка — машина; сумма — столбец, чья шапка
 * про деньги («стоимость», «ставка», «сумма», «оценка», «цена»), без шапки — первое число строки от тысячи, не дата. Итог
 * — тот же текст «номер сумма», VIN и город строками, что у фото: его разбирает `ValuationText`. Листы без номеров
 * (сводки, гистограммы) не дают ничего.
 */
final class ValuationSheet
{
    public const EXTENSIONS = ['xlsx', 'xls', 'csv', 'ods'];

    private const MONEY = '/стоим|ставк|сумм|оцен|цен/ui';

    private const CITY = '/город|филиал|регион|место/ui';

    /** @return array{text: string, sure: bool} */
    public static function read(string $path): array
    {
        $book = IOFactory::load($path);
        $matcher = new CodeMatcher;
        $lines = [];
        foreach ($book->getAllSheets() as $sheet) {
            $money = $city = null;
            $raw = $sheet->toArray(null, true, false, true);
            foreach ($sheet->toArray(null, true, true, true) as $n => $texts) {
                $texts = array_map(fn ($t) => trim((string) $t), $texts);
                $ref = null;
                foreach ($texts as $t) {
                    if ($t !== '' && ($ref = $matcher->findAll($t)[0] ?? null)) {
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
                $number = fn ($col) => self::number($raw[$n][$col] ?? null, fn () => Date::isDateTime($sheet->getCell($col.$n)));
                $amount = $money !== null ? $number($money) : null;
                foreach ($money === null ? array_keys($texts) : [] as $col) {
                    if (($v = $number($col)) !== null && $v >= 1000) {
                        $amount = $v;
                        break;
                    }
                }
                $lines[] = trim($ref.' '.$amount);
                ($vin = Patterns::vins(implode(' ', $texts))[0] ?? null) && $lines[] = $vin;
                $city !== null && ($texts[$city] ?? '') !== '' && $lines[] = $texts[$city];
            }
        }

        return ['text' => implode("\n", $lines), 'sure' => $lines !== []];
    }

    /** Целые рубли из ячейки: число не датой или текст «635 237,00». */
    private static function number(mixed $value, \Closure $isDate): ?int
    {
        if (is_int($value) || is_float($value)) {
            return $value <= 0 || $isDate() ? null : (int) round($value);
        }

        return ValuationText::amount((string) $value);
    }
}
