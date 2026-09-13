<?php

namespace App\Purchases;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/** Предложения менеджеров: сводка по людям, матрица «машина × менеджер» и машины без цен. */
final class OffersExport
{
    private const CAR = ['ДЛ', 'Марка', 'Модель', 'Год', 'Тип', 'Город', 'Наша цена'];

    public function write(Purchase $purchase, string $path): string
    {
        $s = new OffersSummary($purchase);
        $writer = new Writer(new Options);
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        $head = fn (array $cells) => Row::fromValuesWithStyles($cells, array_fill(0, count($cells), $bold));
        $car = fn (Car $c) => [$c->dl, $c->brand?->name ?? $c->brand_raw, $c->model?->name ?? $c->model_raw, $c->year, $c->kind->label(), $c->settlement?->name ?? $c->city, $c->price_listing ?? ''];

        $writer->getCurrentSheet()->setName('Сводка');
        $writer->addRow($head(['Менеджер', 'Телефон', 'Видно машин', 'Предложил', 'Без цены', 'Сумма предложений', 'Выбрано']));
        foreach ($s->managers as $u) {
            $st = $s->stats[$u->id];
            $writer->addRow(Row::fromValues([$u->name, $u->phoneFormatted(), $st['visible'], $st['offered'], $st['missing'], $st['sum'], $st['chosen']]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Машин в закупке', $s->cars->count()]));
        $writer->addRow(Row::fromValues(['С предложениями', $s->priced()]));
        $writer->addRow(Row::fromValues(['Без предложений', $s->unpriced()->count()]));

        $writer->addNewSheetAndMakeItCurrent()->setName('По машинам');
        $writer->addRow($head([...self::CAR, 'Лучшая', ...$s->managers->map->name->all()]));
        foreach ($s->cars as $c) {
            $byUser = $c->activeOfferList()->keyBy('user_id');
            $writer->addRow(Row::fromValues([
                ...$car($c), $c->bestOffer()?->amount ?? '',
                ...$s->managers->map(fn ($u) => $byUser[$u->id]?->amount ?? '')->all(),
            ]));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('Без предложений');
        $writer->addRow($head(self::CAR));
        foreach ($s->unpriced() as $c) {
            $writer->addRow(Row::fromValues($car($c)));
        }
        $writer->close();

        return $path;
    }
}
