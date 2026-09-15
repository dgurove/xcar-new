<?php

namespace App\Offers\Share;

use App\Offers\Offer;
use App\Offers\PriceView;
use App\Purchases\Car;
use App\Users\User;

/**
 * Строки для текста в мессенджер: ключ, подпись, значение, включено ли по
 * умолчанию. Собирает клиент из отмеченных — цена одной строкой, метка НДС в конце.
 */
final class Caption
{
    /** @return list<array{key: string, label: string, value: string, on: bool}> */
    public static function fields(Offer $offer, ?User $user): array
    {
        $money = fn ($v) => $v ? number_format($v, 0, '', ' ') : null;
        $staff = $user?->isStaff() ?? false;
        $price = PriceView::for($offer, $user);
        $rows = [
            ['number', 'Номер', (string) $offer->number, true],
            // «От» перед «до» — клиент склеивает отмеченные цены стрелкой в этом порядке.
            // Менеджеру — заявленная, сотруднику — закупочная и заявленная; по умолчанию выключены: покупателю уходит одна цена.
            ['floor_price', 'Закупочная', $staff ? $money($offer->floor_price) : null, false],
            ['publish_price', 'Заявленная', $staff ? $money($offer->declaredPrice()) : ($price->visible ? $money($price->from) : null), false],
            ['price', 'Цена', $price->visible ? $money($offer->asking_price) : null, true],
            ['city', 'Город', $offer->settlement?->name, true],
            ['model', 'Марка, модель, год', $offer->titleWithYear(), true],
            ['specs', 'КПП, привод, кузов', implode(', ', array_filter([$offer->transmission?->label(), $offer->drive?->label(), $offer->body?->label()])) ?: null, true],
            ['engine', 'Двигатель', $offer->engine_volume ? number_format($offer->engine_volume / 1000, 1, ',', '').' л'.($offer->engine_power ? ', '.$offer->engine_power.' л. с.' : '') : null, false],
            ['mileage', 'Пробег', $offer->mileage !== null ? number_format($offer->mileage, 0, '', ' ').' км' : null, false],
            ['vin', 'VIN', $offer->vin ? 'VIN '.$offer->vin : null, false],
            ['tags', 'Метки', $offer->tags ? implode(', ', $offer->tags) : null, false],
            ['until', 'Приём подтверждений до', $offer->bids_close_at?->translatedFormat('d.m.Y H:i'), false],
        ];

        return self::rows($rows);
    }

    /** Машина закупки: ДЛ и наша цена вместо номера и цены оффера, срок — приём цен по закупке. */
    public static function car(Car $car, ?User $user): array
    {
        $money = fn ($v) => $v ? number_format($v, 0, '', ' ') : null;
        $prices = $user?->role->canSeePrices() ?? false;
        $rows = [
            ['number', 'Номер', str_starts_with(mb_strtoupper($car->dl), 'ДЛ') ? $car->dl : 'ДЛ '.$car->dl, true],
            ['price', 'Наша цена', $prices ? $money($car->price_listing) : null, true],
            ['city', 'Город', $car->settlement?->name ?? $car->city, true],
            ['model', 'Марка, модель, год', $car->titleWithYear(), true],
            ['specs', 'КПП, топливо', implode(', ', array_filter([$car->transmission?->label(), $car->fuel?->label()])) ?: null, true],
            ['engine', 'Двигатель', $car->engine_volume ? number_format($car->engine_volume / 1000, 1, ',', '').' л'.($car->engine_power ? ', '.$car->engine_power.' л. с.' : '') : null, false],
            ['mileage', 'Пробег', $car->mileage !== null ? number_format($car->mileage, 0, '', ' ').' км' : null, false],
            ['vin', 'VIN', $car->vin ? 'VIN '.$car->vin : null, false],
            ['encumbrance', 'Обременения', $car->encumbrance, false],
            ['until', 'Приём цен до', $car->purchase?->offers_close_at?->translatedFormat('d.m.Y H:i'), false],
        ];

        return self::rows($rows);
    }

    private static function rows(array $rows): array
    {
        return array_values(array_map(fn ($r) => ['key' => $r[0], 'label' => $r[1], 'value' => (string) $r[2], 'on' => $r[3]],
            array_filter($rows, fn ($r) => $r[2] !== null && $r[2] !== '')));
    }

    public static function vatMark(Offer $offer): string
    {
        return $offer->prices_include_vat ? ' с НДС' : ' без НДС';
    }
}
