<?php

namespace App\Purchases;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Единственная выгрузка закупки. Excel — тот самый файл Carcade с диска
 * (`source_file`, все листы и колонки как есть), в «Предложение клиента» —
 * наша цена (`price_final`); по галке «Цены менеджеров» на листе с машинами
 * справа «Максимальная», «Минимальная» и колонка на менеджера. PDF — та же
 * таблица компактно. Остальные галки: лист «Сводка», строки с предложениями, строки без.
 */
final class Export
{
    public const PARTS = ['summary' => 'Сводка', 'priced' => 'С предложениями', 'unpriced' => 'Без предложений', 'managers' => 'Цены менеджеров'];

    private const CAR = ['ДЛ', 'Марка', 'Модель', 'Год', 'Тип', 'Город', 'Размещение', 'Наша цена'];

    private const FILL = 'F0F7D8';

    private const SUMMARY_HEAD = ['Менеджер', 'Телефон', 'Видно машин', 'Предложил', 'Без цены', 'Выбрано'];

    /** @param list<string> $parts */
    public function xlsx(Purchase $purchase, array $parts, string $path): string
    {
        $file = $purchase->source_file ? Storage::disk('private')->path($purchase->source_file) : null;
        if (! $file || ! is_file($file)) {
            throw new RuntimeException('Файла поставщика нет — загрузите его в шторке');
        }
        $s = new OffersSummary($purchase);
        $cars = $s->cars->keyBy(fn (Car $c) => mb_strtolower(trim($c->dl)));
        $book = IOFactory::load($file);
        [$sheet, $headerRow, $dlCol, $lastCol] = $this->carsSheet($book);

        // Умная таблица после удаления строк не сходится с диапазоном — Excel просит «восстановить». Вместо неё автофильтр.
        foreach ($sheet->getTableCollection() as $table) {
            $sheet->removeTableByName($table->getName());
        }
        $keepPriced = in_array('priced', $parts, true);
        $keepUnpriced = in_array('unpriced', $parts, true);
        $highest = $sheet->getHighestDataRow();
        for ($r = $highest; $r > $headerRow; $r--) {
            $dl = mb_strtolower(trim((string) $sheet->getCell([$dlCol, $r])->getValue()));
            if ($dl === '') {
                continue;
            }
            $priced = isset($cars[$dl]) && $cars[$dl]->activeOfferList()->isNotEmpty();
            if ($priced ? ! $keepPriced : ! $keepUnpriced) {
                $sheet->removeRow($r);
            }
        }

        $clientCol = $this->column($sheet, $headerRow, $lastCol, 'предложение клиента');
        $head = in_array('managers', $parts, true) ? ['Максимальная', 'Минимальная', ...$s->managers->map->name->all()] : [];
        $headStyle = $sheet->getStyle([$dlCol, $headerRow]);
        foreach ($head as $i => $title) {
            $col = $lastCol + 1 + $i;
            $cell = $sheet->getCell([$col, $headerRow]);
            $cell->setValue($title);
            $cell->getStyle()->applyFromArray($headStyle->exportArray());
            $cell->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FILL);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(14);
        }
        $last = $sheet->getHighestDataRow();
        for ($r = $headerRow + 1; $r <= $last; $r++) {
            $car = $cars[mb_strtolower(trim((string) $sheet->getCell([$dlCol, $r])->getValue()))] ?? null;
            if ($clientCol && $car?->price_final) {
                $sheet->getCell([$clientCol, $r])->setValue($car->price_final)->getStyle()->getNumberFormat()->setFormatCode('#,##0');
            }
            $offers = $car?->activeOfferList();
            $values = $head && $offers && $offers->isNotEmpty()
                ? [$offers->max('amount'), $offers->min('amount'), ...$s->managers->map(fn ($u) => $offers->firstWhere('user_id', $u->id)?->amount)->all()]
                : [];
            foreach ($values as $i => $v) {
                if ($v !== null) {
                    $sheet->getCell([$lastCol + 1 + $i, $r])->setValue($v)->getStyle()->getNumberFormat()->setFormatCode('#,##0');
                }
            }
        }
        $end = Coordinate::stringFromColumnIndex($lastCol + count($head));
        $sheet->setAutoFilter('A'.$headerRow.':'.$end.max($last, $headerRow));
        $sheet->freezePane('A'.($headerRow + 1));

        if (in_array('summary', $parts, true)) {
            $this->summarySheet($book, $s);
        }
        $book->setActiveSheetIndex($book->getIndex($sheet));
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /** Номер колонки с таким заголовком в строке шапки (без учёта регистра и лишних пробелов), нет — null. */
    private function column(Worksheet $sheet, int $headerRow, int $lastCol, string $title): ?int
    {
        for ($c = 1; $c <= $lastCol; $c++) {
            $cell = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $sheet->getCell([$c, $headerRow])->getValue()) ?? ''));
            if ($cell === $title) {
                return $c;
            }
        }

        return null;
    }

    /** Первый видимый лист с колонкой «ДЛ» в первых десяти строках: [лист, строка заголовка, колонка ДЛ, последняя колонка]. */
    private function carsSheet(Spreadsheet $book): array
    {
        foreach ($book->getAllSheets() as $sheet) {
            if ($sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
                continue;
            }
            $lastCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            for ($r = 1; $r <= min(10, $sheet->getHighestDataRow()); $r++) {
                for ($c = 1; $c <= $lastCol; $c++) {
                    $title = mb_strtolower(str_replace('ё', 'е', preg_replace('/\s+/u', ' ', (string) $sheet->getCell([$c, $r])->getValue()) ?? ''));
                    if (trim($title) === 'дл') {
                        $end = $lastCol;
                        while ($end > $c && trim((string) $sheet->getCell([$end, $r])->getValue()) === '') {
                            $end--;
                        }

                        return [$sheet, $r, $c, $end];
                    }
                }
            }
        }
        throw new RuntimeException('В файле поставщика нет колонки «ДЛ»');
    }

    private function summarySheet(Spreadsheet $book, OffersSummary $s): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('Сводка');
        $sheet->fromArray(self::SUMMARY_HEAD, null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->fromArray($this->summaryRows($s), null, 'A2');
        foreach (['A' => 28, 'B' => 18, 'C' => 13, 'D' => 12, 'E' => 11, 'F' => 10] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
    }

    private function summaryRows(OffersSummary $s): array
    {
        return [
            ...$s->managers->map(fn ($u) => [$u->name, $u->phoneFormatted(), ...array_values($s->stats[$u->id])])->all(),
            [],
            ['Машин в закупке', $s->cars->count()],
            ['С предложениями', $s->priced()],
            ['Без предложений', $s->unpriced()->count()],
        ];
    }

    /**
     * Таблицы для PDF: «Сводка» и машины по тем же галкам; колонки менеджеров — по галке «managers».
     *
     * @return list<array{name:string, head:list<string>, rows:list<list<mixed>>}>
     */
    public function tables(Purchase $purchase, array $parts): array
    {
        $s = new OffersSummary($purchase);
        $s->cars->load(['brand', 'model', 'settlement']);
        $car = fn (Car $c) => [$c->dl, $c->brand?->name ?? $c->brand_raw, $c->model?->name ?? $c->model_raw, $c->year, $c->kind->label(), $c->settlement?->name ?? $c->city, $c->price_listing ?? '', $c->price_final ?? ''];
        $managers = in_array('managers', $parts, true);
        $matrix = fn (Car $c) => $managers ? [
            ...$car($c), $c->activeOfferList()->max('amount') ?? '', $c->activeOfferList()->min('amount') ?? '',
            ...$s->managers->map(fn ($u) => $c->activeOfferList()->firstWhere('user_id', $u->id)?->amount ?? '')->all(),
        ] : $car($c);
        $matrixHead = $managers ? [...self::CAR, 'Максимальная', 'Минимальная', ...$s->managers->map->shortName()->all()] : self::CAR;
        $priced = in_array('priced', $parts, true);
        $unpriced = in_array('unpriced', $parts, true);
        $tables = [];
        if (in_array('summary', $parts, true)) {
            $tables[] = ['name' => 'Сводка', 'head' => self::SUMMARY_HEAD, 'rows' => $this->summaryRows($s)];
        }
        if ($priced && $unpriced) {
            $tables[] = ['name' => 'Все', 'head' => $matrixHead, 'rows' => $s->cars->map($matrix)->all()];
        } elseif ($priced) {
            $tables[] = ['name' => 'С предложениями', 'head' => $matrixHead, 'rows' => $s->cars->filter(fn (Car $c) => $c->activeOfferList()->isNotEmpty())->map($matrix)->values()->all()];
        } elseif ($unpriced) {
            $tables[] = ['name' => 'Без предложений', 'head' => self::CAR, 'rows' => $s->unpriced()->map($car)->all()];
        }

        return $tables;
    }

    public function pdf(array $tables, Purchase $purchase, string $path): string
    {
        // Шрифт бренда и логотип — файлами из проекта, кэш шрифтов и временные файлы — в storage: vendor на сервере не для записи.
        $dir = storage_path('app/private/dompdf');
        @mkdir($dir, 0775, true);
        $options = (new Options)
            ->setIsRemoteEnabled(false)
            ->setDefaultFont('Onest')
            ->setDefaultPaperSize('a4')
            ->setDefaultPaperOrientation('landscape')
            ->setTempDir($dir)
            ->setFontCache($dir)
            ->setChroot([$dir, resource_path('fonts/pdf'), public_path('images')]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('admin.purchases.offers-pdf', ['purchase' => $purchase, 'tables' => $tables])->render());
        // dompdf держит кадр на каждую ячейку: две матрицы по 500 машин и 10 человек — полгигабайта.
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', '1G');
        try {
            $pdf->render();
            $canvas = $pdf->getCanvas();
            $font = $pdf->getFontMetrics()->getFont('Onest');
            $canvas->page_text(34, $canvas->get_height() - 28, $purchase->title ?: $purchase->publicTitle(), $font, 7.5, [.5, .5, .5]);
            $canvas->page_text($canvas->get_width() - 60, $canvas->get_height() - 28, '{PAGE_NUM} / {PAGE_COUNT}', $font, 7.5, [.5, .5, .5]);
            file_put_contents($path, $pdf->output());
        } finally {
            // Обратно лимит опускается только ниже занятого: иначе PHP ругается, а воркер Octane живёт с 1G.
            unset($canvas, $pdf);
            gc_collect_cycles();
            if (memory_get_usage(true) < ini_parse_quantity($limit)) {
                ini_set('memory_limit', $limit);
            }
        }

        return $path;
    }
}
