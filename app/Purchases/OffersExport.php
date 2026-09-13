<?php

namespace App\Purchases;

use Dompdf\Dompdf;
use Dompdf\Options;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Предложения менеджеров файлом: листы по выбору — сводка по людям, матрица
 * «машина × менеджер» (все машины или только с ценами) и машины без цен.
 * Одни таблицы, два писателя: xlsx и pdf.
 */
final class OffersExport
{
    public const SHEETS = ['summary' => 'Сводка', 'all' => 'Все', 'priced' => 'С предложениями', 'unpriced' => 'Без предложений'];

    public const DEFAULT = ['summary', 'all', 'unpriced'];

    private const CAR = ['ДЛ', 'Марка', 'Модель', 'Год', 'Тип', 'Город', 'Наша цена'];

    /** @return list<array{name:string, head:list<string>, rows:list<list<mixed>>}> */
    public function tables(Purchase $purchase, array $sheets): array
    {
        $s = new OffersSummary($purchase);
        $s->cars->load(['brand', 'model', 'settlement']);
        $car = fn (Car $c) => [$c->dl, $c->brand?->name ?? $c->brand_raw, $c->model?->name ?? $c->model_raw, $c->year, $c->kind->label(), $c->settlement?->name ?? $c->city, $c->price_listing ?? ''];
        $matrix = fn (Car $c) => [
            ...$car($c), $c->activeOfferList()->max('amount') ?? '', $c->activeOfferList()->min('amount') ?? '',
            ...$s->managers->map(fn ($u) => $c->activeOfferList()->firstWhere('user_id', $u->id)?->amount ?? '')->all(),
        ];
        $matrixHead = [...self::CAR, 'Максимальная', 'Минимальная', ...$s->managers->map->name->all()];
        $tables = [];
        foreach (array_keys(self::SHEETS) as $key) {
            if (! in_array($key, $sheets, true)) {
                continue;
            }
            $tables[] = ['name' => self::SHEETS[$key]] + match ($key) {
                'summary' => [
                    'head' => ['Менеджер', 'Телефон', 'Видно машин', 'Предложил', 'Без цены', 'Сумма предложений', 'Выбрано'],
                    'rows' => [
                        ...$s->managers->map(fn ($u) => [$u->name, $u->phoneFormatted(), ...array_values($s->stats[$u->id])])->all(),
                        [],
                        ['Машин в закупке', $s->cars->count()],
                        ['С предложениями', $s->priced()],
                        ['Без предложений', $s->unpriced()->count()],
                    ],
                ],
                'all' => ['head' => $matrixHead, 'rows' => $s->cars->map($matrix)->all()],
                'priced' => ['head' => $matrixHead, 'rows' => $s->cars->filter(fn (Car $c) => $c->activeOfferList()->isNotEmpty())->map($matrix)->values()->all()],
                'unpriced' => ['head' => self::CAR, 'rows' => $s->unpriced()->map($car)->all()],
            };
        }

        return $tables;
    }

    public function xlsx(array $tables, string $path): string
    {
        $writer = new Writer(new XlsxOptions);
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        foreach ($tables as $i => $t) {
            ($i ? $writer->addNewSheetAndMakeItCurrent() : $writer->getCurrentSheet())->setName($t['name']);
            $writer->addRow(Row::fromValuesWithStyles($t['head'], array_fill(0, count($t['head']), $bold)));
            foreach ($t['rows'] as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }
        $writer->close();

        return $path;
    }

    public function pdf(array $tables, Purchase $purchase, string $path): string
    {
        // Шрифты штатные (DejaVu с кириллицей), а кэш и временные файлы — в storage: vendor на сервере не для записи.
        $dir = storage_path('app/private/dompdf');
        @mkdir($dir, 0775, true);
        $options = (new Options)
            ->setIsRemoteEnabled(false)
            ->setDefaultFont('DejaVu Sans')
            ->setDefaultPaperSize('a4')
            ->setDefaultPaperOrientation('landscape')
            ->setTempDir($dir)
            ->setFontCache($dir)
            ->setChroot($dir);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('admin.purchases.offers-pdf', ['purchase' => $purchase, 'tables' => $tables])->render());
        $pdf->render();
        file_put_contents($path, $pdf->output());

        return $path;
    }
}
