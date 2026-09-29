<?php

namespace App\Offers\Share;

use App\Offers\Offer;
use App\Offers\PriceView;
use App\Purchases\Car;
use App\Support\Surface;
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
        // Цены — последней строкой: клиент собирает отмеченные сверху вниз, цены склеивает стрелкой «от → до» в конце.
        // VIN менеджеру — как на сайте: скрытый глазиком уходит маской.
        $rows = [
            ['number', 'Номер', '#'.$offer->number, true],
            ['dl', 'ДЛ', $offer->leaseRef() ? 'ДЛ '.$offer->leaseRef() : null, true],
            ['model', 'Марка, модель, год', $offer->titleWithYear(), true],
            ['city', 'Город', $offer->settlement?->name, true],
            ['specs', 'КПП, привод, кузов', implode(', ', array_filter([$offer->transmission?->label(), $offer->drive?->label(), $offer->body?->label()])) ?: null, true],
            ['engine', 'Двигатель', $offer->engine_volume ? number_format($offer->engine_volume / 1000, 1, ',', '').' л'.($offer->engine_power ? ', '.$offer->engine_power.' л. с.' : '') : null, true],
            ['mileage', 'Пробег', $offer->mileage !== null ? number_format($offer->mileage, 0, '', ' ').' км' : null, false],
            ['vin', 'VIN', $offer->vin ? 'VIN '.($staff ? $offer->vin : $offer->vinMasked()) : null, true],
            ['tags', 'Метки', $offer->tags ? implode(', ', $offer->tags) : null, false],
            ['until', 'Приём подтверждений до', $offer->bids_close_at?->translatedFormat('d.m.Y H:i'), false],
            // Закупочная и заявленная — «от», из двух одна (клиент снимает вторую); по умолчанию выключены: покупателю уходит одна цена.
            ['floor_price', 'Закупочная', $staff ? $money($offer->floor_price) : null, false],
            // Сотруднику «Заявленная» — только когда она вписана: пустая равна закупочной, и под этим именем в текст уходила закупочная.
            ['publish_price', 'Заявленная', $staff ? $money($offer->publish_price) : ($price->visible ? $money($price->declared) : null), false],
            ['price', 'Цена', $price->visible ? $money($offer->asking_price) : null, true],
            // Ссылка на машину на сайте — последней строкой; у черновика своего адреса ещё нет (номер при публикации).
            ['link', 'Ссылка', self::link($offer), true],
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
            ['city', 'Город', $car->settlement?->name ?? $car->city, true],
            ['model', 'Марка, модель, год', $car->titleWithYear(), true],
            ['specs', 'КПП, топливо', implode(', ', array_filter([$car->transmission?->label(), $car->fuel?->label()])) ?: null, true],
            ['engine', 'Двигатель', $car->engine_volume ? number_format($car->engine_volume / 1000, 1, ',', '').' л'.($car->engine_power ? ', '.$car->engine_power.' л. с.' : '') : null, true],
            ['mileage', 'Пробег', $car->mileage !== null ? number_format($car->mileage, 0, '', ' ').' км' : null, false],
            ['vin', 'VIN', $car->vin ? 'VIN '.$car->vin : null, true],
            ['encumbrance', 'Обременения', $car->encumbrance, false],
            ['until', 'Приём цен до', $car->purchase?->offers_close_at?->translatedFormat('d.m.Y H:i'), false],
            ['price', 'Наша цена', $prices ? $money($car->price_listing) : null, true],
            ['link', 'Ссылка', $car->purchase ? Surface::Site->url("/purchases/{$car->purchase->number}/{$car->ref}") : null, true],
        ];

        return self::rows($rows);
    }

    /** Адрес предложения на сайте — для текста и «Скопировать ссылку». */
    public static function link(Offer $offer): ?string
    {
        return $offer->published_at ? Surface::Site->url("/offers/{$offer->number}") : null;
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
