<?php

namespace App\Billing\Acts;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use RuntimeException;
use ZipArchive;

/**
 * Прежний файл страховой, заполненный нашими данными (`resources/templates/acts`). Метки — `{имя}` в тексте ячейки или
 * абзаца; строка таблицы, где стоят метки строк, — образец: размножается на каждую строку данных со своим видом
 * (стиль, объединения, высота). Ячейка «#=…» — формула: `{r}` — её строка, `{first}` / `{last}` — первая и последняя
 * строки данных. Ячейка целиком из одной метки получает значение своим типом: число — числом, дата — датой Excel.
 * В Word строка-образец — `<w:tr>` с метками строк, формул нет.
 */
final class Template
{
    /**
     * @param  array<string, scalar|DateTimeInterface|null>  $vars
     * @param  list<array<string, scalar|DateTimeInterface|null>>  $rows
     * @param  list<string>  $rowKeys  метки строк — по ним находится строка-образец
     */
    public static function fill(string $template, array $vars, array $rows, array $rowKeys): string
    {
        $path = resource_path('templates/acts/'.$template);
        $out = tempnam(sys_get_temp_dir(), 'act-').'.'.pathinfo($template, PATHINFO_EXTENSION);
        match (pathinfo($template, PATHINFO_EXTENSION)) {
            'xlsx' => self::xlsx($path, $out, $vars, $rows, $rowKeys),
            'docx' => self::docx($path, $out, $vars, $rows, $rowKeys),
        };

        return $out;
    }

    private static function xlsx(string $path, string $out, array $vars, array $rows, array $rowKeys): void
    {
        $book = IOFactory::load($path);
        $sheet = $book->getActiveSheet();
        $isRowCell = fn ($v) => is_string($v) && (bool) array_filter([...$rowKeys, '#', 'r'], fn ($k) => str_contains($v, '{'.$k.'}'));
        $sample = null;
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($isRowCell($cell->getValue())) {
                    $sample = $row->getRowIndex();
                    break 2;
                }
            }
        }
        if (! $sample) {
            throw new RuntimeException('В шаблоне '.basename($path).' нет строки-образца');
        }
        $cells = [];
        foreach ($sheet->getRowIterator($sample, $sample)->current()->getCellIterator() as $cell) {
            if ($cell->getValue() !== null && $cell->getValue() !== '') {
                $cells[$cell->getColumn()] = (string) $cell->getValue();
            }
        }
        $merges = array_values(array_filter($sheet->getMergeCells(), fn ($m) => preg_match('/^([A-Z]+)'.$sample.':([A-Z]+)'.$sample.'$/', $m)));
        $height = $sheet->getRowDimension($sample)->getRowHeight();
        $n = max(1, count($rows));
        if ($n > 1) {
            $sheet->insertNewRowBefore($sample + 1, $n - 1);
        }
        $first = $sample;
        $last = $sample + $n - 1;
        $hi = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        foreach (range(0, $n - 1) as $i) {
            $r = $sample + $i;
            $data = ($rows[$i] ?? []) + ['#' => $rows ? $i + 1 : null, 'r' => $r];
            if ($i > 0) {
                // Вид строки — как у образца: стиль по ячейкам, объединения, высота.
                for ($c = 1; $c <= $hi; $c++) {
                    $col = Coordinate::stringFromColumnIndex($c);
                    $sheet->duplicateStyle($sheet->getStyle($col.$sample), $col.$r);
                }
                foreach ($merges as $m) {
                    $sheet->mergeCells(str_replace((string) $sample, (string) $r, $m));
                }
                $sheet->getRowDimension($r)->setRowHeight($height);
            }
            foreach ($cells as $col => $text) {
                self::put($sheet, $col.$r, $text, $data + ['first' => $first, 'last' => $last]);
            }
        }
        $all = $vars + ['first' => $first, 'last' => $last];
        foreach ($sheet->getRowIterator() as $row) {
            if ($row->getRowIndex() >= $first && $row->getRowIndex() <= $last) {
                continue;
            }
            foreach ($row->getCellIterator() as $cell) {
                $v = $cell->getValue();
                if (is_string($v) && (str_contains($v, '{') || str_starts_with($v, '#='))) {
                    self::put($sheet, $cell->getCoordinate(), $v, $all);
                }
            }
        }
        IOFactory::createWriter($book, 'Xlsx')->save($out);
    }

    private static function put($sheet, string $coord, string $text, array $data): void
    {
        $cell = $sheet->getCell($coord);
        if (str_starts_with($text, '#=')) {
            $cell->setValue('='.self::subst(substr($text, 2), $data, false));

            return;
        }
        if (preg_match('/^\{([^}]+)\}$/', trim($text), $m) && array_key_exists($m[1], $data)) {
            $v = $data[$m[1]];
            if ($v instanceof DateTimeInterface) {
                $cell->setValue(ExcelDate::PHPToExcel($v));
                $format = $sheet->getStyle($coord)->getNumberFormat();
                if (in_array($format->getFormatCode(), [NumberFormat::FORMAT_GENERAL, '@'], true)) {
                    $format->setFormatCode('dd.mm.yyyy');
                }

                return;
            }
            if (is_int($v) || is_float($v)) {
                $cell->setValueExplicit($v, DataType::TYPE_NUMERIC);

                return;
            }
        }
        $value = self::subst($text, $data, false);
        $cell->setValueExplicit($value === '' ? null : $value, $value === '' ? DataType::TYPE_NULL : DataType::TYPE_STRING);
    }

    private static function subst(string $text, array $data, bool $xml): string
    {
        return preg_replace_callback('/\{([^}{]+)\}/', function ($m) use ($data, $xml) {
            if (! array_key_exists($m[1], $data)) {
                return $m[0];
            }
            $v = $data[$m[1]];
            $s = $v instanceof DateTimeInterface ? $v->format('d.m.Y') : (string) $v;

            return $xml ? str_replace("\n", '</w:t><w:br/><w:t xml:space="preserve">', htmlspecialchars($s, ENT_XML1)) : $s;
        }, $text);
    }

    private static function docx(string $path, string $out, array $vars, array $rows, array $rowKeys): void
    {
        copy($path, $out);
        $zip = new ZipArchive;
        $zip->open($out);
        $xml = $zip->getFromName('word/document.xml');
        $xml = preg_replace_callback('/<w:tr[ >](?:(?!<w:tr[ >]).)*?<\/w:tr>/s', function ($m) use ($rows, $rowKeys) {
            $tr = $m[0];
            if (! array_filter([...$rowKeys, '#'], fn ($k) => str_contains($tr, '{'.$k.'}'))) {
                return $tr;
            }

            return implode('', array_map(fn ($i) => self::subst($tr, $rows[$i] + ['#' => $i + 1], true), array_keys($rows)));
        }, $xml);
        $xml = self::subst($xml, $vars, true);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
    }
}
