<?php

namespace App\Offers\Share;

use App\Offers\Offer;
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
        $prices = $user?->role->canSeePrices() ?? false;
        $rows = [
            ['number', 'Номер', (string) $offer->number, true],
            ['price', 'Цена', $prices ? $money($offer->asking_price) : null, true],
            ['floor_price', 'Нижняя от страховой', $staff ? $money($offer->floor_price) : null, false],
            ['city', 'Город', $offer->settlement?->name, true],
            ['model', 'Марка, модель, год', $offer->titleWithYear(), true],
            ['specs', 'КПП, привод, кузов', implode(', ', array_filter([$offer->transmission?->label(), $offer->drive?->label(), $offer->body?->label()])) ?: null, true],
            ['engine', 'Двигатель', $offer->engine_volume ? number_format($offer->engine_volume / 1000, 1, ',', '').' л'.($offer->engine_power ? ', '.$offer->engine_power.' л. с.' : '') : null, false],
            ['mileage', 'Пробег', $offer->mileage !== null ? number_format($offer->mileage, 0, '', ' ').' км' : null, false],
            ['vin', 'VIN', $offer->vin ? 'VIN '.$offer->vin : null, false],
            ['tags', 'Метки', $offer->tags ? implode(', ', $offer->tags) : null, false],
            ['until', 'Приём подтверждений до', $offer->bids_close_at?->translatedFormat('d.m.Y H:i'), false],
        ];

        return array_values(array_map(fn ($r) => ['key' => $r[0], 'label' => $r[1], 'value' => (string) $r[2], 'on' => $r[3]],
            array_filter($rows, fn ($r) => $r[2] !== null && $r[2] !== '')));
    }

    public static function vatMark(Offer $offer): string
    {
        return match ($offer->prices_include_vat) {
            true => ' с НДС',
            false => ' без НДС',
            default => '',
        };
    }
}
