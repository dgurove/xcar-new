<?php

namespace App\Purchases;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/** Тот же файл поставщика с заполненной колонкой «Предложение клиента», вторым листом — все цены. */
final class Export
{
    private const HEAD = ['ДЛ', 'Этап ПЛ', 'Марка', 'Модель', 'Год выпуска', 'Тип ПЛ полностью', 'Местонахождение', 'Цена ТС с учетом переоценки с НДС', 'Цена для размещения с НДС', 'Предложение клиента', 'Тяжелые ограничения', 'Обременения', 'Ссылка на сайт', 'Ссылка на облако'];

    private const HEAD_OFFERS = ['ДЛ', 'Марка', 'Модель', 'Год', 'Кто предложил', 'Телефон', 'Цена', 'Комментарий', 'Когда', 'Состояние'];

    public function write(Purchase $purchase, string $path): string
    {
        $writer = new Writer(new Options);
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        $writer->getCurrentSheet()->setName('ИТОГ');
        $writer->addRow(Row::fromValuesWithStyles(self::HEAD, array_fill(0, count(self::HEAD), $bold)));
        $cars = $purchase->cars()->with(['brand', 'model', 'offers.user'])->orderBy('dl')->get();
        foreach ($cars as $car) {
            $best = $car->bestOffer();
            $writer->addRow(Row::fromValues([
                $car->dl, $car->stage, $car->brand?->name ?? $car->brand_raw, $car->model?->name ?? $car->model_raw, $car->year, $car->vehicle_type, $car->address,
                $car->price_revalued ?? '', $car->price_listing ?? '', $best?->amount ?? '', 'Нет', $car->encumbrance, $car->site_url, $car->cloud_url,
            ]));
        }
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Предложения');
        $writer->addRow(Row::fromValuesWithStyles(self::HEAD_OFFERS, array_fill(0, count(self::HEAD_OFFERS), $bold)));
        foreach ($cars as $car) {
            foreach ($car->offers->whereIn('state', [OfferState::Active, OfferState::Chosen]) as $offer) {
                $writer->addRow(Row::fromValues([$car->dl, $car->brand?->name ?? $car->brand_raw, $car->model?->name ?? $car->model_raw, $car->year, $offer->user?->name, $offer->user?->phone, $offer->amount, $offer->comment, $offer->created_at?->format('d.m.Y H:i'), $offer->state->label()]));
            }
        }
        $writer->close();

        return $path;
    }
}
