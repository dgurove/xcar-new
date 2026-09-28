<?php

namespace App\Purchases;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Ответ поставщика на нашу выгрузку: лист, где рядом с «ДЛ» есть «Итоговая цена контрпредложения».
 * Остальные листы (у Carcade — «исключения») пропускаются сами: такой колонки там нет. Цена в их файле —
 * формула на чужую книгу, поэтому берётся посчитанное Excel значение, а не текст формулы.
 */
final class CounterReader
{
    private const PRICE = 'итоговая цена контрпредложения';

    /** @return list<array{dl: string, brand: ?string, model: ?string, price: ?int}> */
    public function read(string $path): array
    {
        $reader = new Reader(new Options);
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if (! $sheet->isVisible()) {
                    continue;
                }
                $map = null;
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn (Cell $c) => $c instanceof FormulaCell ? $c->getComputedValue() : $c->getValue(), $row->cells);
                    $cells = array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $cells);
                    if ($map === null) {
                        $map = $this->header($cells);

                        continue;
                    }
                    $dl = $cells[$map['dl']] ?? '';
                    if ($dl === '' || mb_strtolower($dl) === 'итого:') {
                        continue;
                    }
                    $price = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], $cells[$map['price']] ?? '');
                    $rows[] = [
                        'dl' => $dl,
                        'brand' => isset($map['brand']) ? ($cells[$map['brand']] ?? null) ?: null : null,
                        'model' => isset($map['model']) ? ($cells[$map['model']] ?? null) ?: null : null,
                        'price' => is_numeric($price) && (float) $price > 0 ? (int) round((float) $price) : null,
                    ];
                }
                if ($map !== null) {
                    return $rows;
                }
            }
        } finally {
            $reader->close();
        }
        throw new RuntimeException('В файле нет листа с колонками «ДЛ» и «Итоговая цена контрпредложения»');
    }

    private function header(array $cells): ?array
    {
        $map = [];
        foreach ($cells as $i => $title) {
            $key = mb_strtolower(str_replace('ё', 'е', trim(preg_replace('/\s+/u', ' ', $title) ?? '')));
            match ($key) {
                'дл' => $map['dl'] = $i,
                'марка' => $map['brand'] = $i,
                'модель' => $map['model'] = $i,
                self::PRICE => $map['price'] = $i,
                default => null,
            };
        }

        return isset($map['dl'], $map['price']) ? $map : null;
    }
}
