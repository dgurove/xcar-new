<?php

namespace App\Purchases;

use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/** Файл поставщика. Колонки ищутся по заголовку, а не по номеру: файл собирает человек в Excel. */
final class XlsxReader
{
    private const COLUMNS = [
        'дл' => 'dl', 'этап пл' => 'stage', 'марка' => 'brand', 'модель' => 'model', 'год выпуска' => 'year',
        'тип пл' => 'kind_code', 'тип пл полностью' => 'vehicle_type', 'местонахождение' => 'address',
        'цена тс с учетом переоценки с ндс' => 'price_revalued', 'цена для размещения с ндс' => 'price_listing',
        'предложение клиента' => 'client_offer', 'тяжелые ограничения' => 'hard_limits', 'обременения' => 'encumbrance',
        'ссылка на сайт' => 'site_url', 'ссылка на облако' => 'cloud_url',
    ];

    /** @return list<array<string, mixed>> строки с ключами колонок, `line`, `problems` */
    public function read(string $path): array
    {
        $reader = new Reader(new Options);
        $reader->open($path);
        $titles = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if (! $sheet->isVisible()) {
                    continue;
                }
                $map = null;
                $rows = [];
                foreach ($sheet->getRowIterator() as $line => $row) {
                    $cells = array_map(fn ($v) => trim(is_scalar($v) ? (string) $v : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : '')), $row->toArray());
                    if ($map === null) {
                        $map = $this->header($cells);
                        if ($map === null) {
                            $titles = array_merge($titles, array_filter($cells));
                        }
                        continue;
                    }
                    if (implode('', $cells) === '') {
                        continue;
                    }
                    $rows[] = $this->row($line, $cells, $map);
                }
                if ($map !== null) {
                    return $rows;
                }
            }
        } finally {
            $reader->close();
        }
        throw new RuntimeException('Ни на одном листе нет колонки «ДЛ». Найдены: '.implode(', ', array_slice(array_unique($titles), 0, 15)));
    }

    private function header(array $cells): ?array
    {
        $map = [];
        foreach ($cells as $i => $title) {
            $key = mb_strtolower(str_replace('ё', 'е', preg_replace('/\s+/u', ' ', $title) ?? ''));
            if (isset(self::COLUMNS[$key])) {
                $map[self::COLUMNS[$key]] = $i;
            }
        }

        return isset($map['dl']) ? $map : null;
    }

    private function row(int $line, array $cells, array $map): array
    {
        $get = fn (string $key) => isset($map[$key], $cells[$map[$key]]) && $cells[$map[$key]] !== '' ? $cells[$map[$key]] : null;
        $problems = [];
        $url = function (?string $v, string $what) use (&$problems) {
            if ($v === null) {
                return null;
            }
            if (str_starts_with($v, '=')) {
                $problems[] = "{$what} пришла формулой: пересохраните файл значениями";

                return null;
            }

            return preg_match('~^https?://[^/]+/.+~', $v) ? $v : null;
        };
        $money = function (?string $v) {
            if ($v === null) {
                return null;
            }
            $clean = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], $v);

            return is_numeric($clean) ? (int) round((float) $clean) : null;
        };
        $text = fn (?string $v) => $v === null ? null : (preg_replace('/\s+/u', ' ', trim($v)) ?: null);
        $dl = $get('dl');
        if ($dl === null) {
            $problems[] = 'пустой номер ДЛ';
        }

        return [
            'line' => $line,
            'dl' => $dl,
            'brand' => $text($get('brand')),
            'model' => $text($get('model')),
            'year' => $get('year') ? (int) preg_replace('/\D+/', '', $get('year')) ?: null : null,
            'vehicle_type' => $text($get('vehicle_type')),
            'kind' => Kind::fromSupplier($get('kind_code'), $get('vehicle_type')),
            'address' => $text($get('address')),
            'price_revalued' => $money($get('price_revalued')),
            'price_listing' => $money($get('price_listing')),
            'encumbrance' => $text($get('encumbrance')),
            'stage' => $text($get('stage')),
            'site_url' => $url($get('site_url'), 'ссылка на сайт'),
            'cloud_url' => $url($get('cloud_url'), 'ссылка на облако'),
            'problems' => $problems,
        ];
    }
}
