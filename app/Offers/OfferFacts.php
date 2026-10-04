<?php

namespace App\Offers;

use App\Cars\DamageZone;
use App\Support\Liters;
use App\Support\Money;

/**
 * «Характеристики» ТС словами — одна дверь на витрину, страницу ТС в гараже, вывоз и сделку менеджера.
 * $fullVin — VIN целиком (тем, кто с машиной работает), иначе как на витрине (`vinMasked`).
 */
final class OfferFacts
{
    /** Подписи во всю ширину сетки. */
    public const WIDE = ['VIN', 'Осмотр', 'Повреждения'];

    /** @return array<string, string> */
    public static function for(Offer $offer, bool $fullVin = false): array
    {
        return array_filter([
            'Год' => $offer->year,
            'Пробег' => $offer->mileage !== null ? Money::nums($offer->mileage).' км' : null,
            'Кузов' => $offer->body?->label(), 'КПП' => $offer->transmission?->label(), 'Привод' => $offer->drive?->label(),
            'Топливо' => $offer->fuel?->label(), 'Объём' => $offer->engine_volume ? Liters::format($offer->engine_volume).' л' : null,
            'Мощность' => $offer->engine_power ? $offer->engine_power.' л. с.' : null, 'Цвет' => $offer->color,
            'VIN' => $fullVin ? $offer->vin : $offer->vinMasked(), 'Причина' => $offer->damage_cause?->label(),
            'Повреждения' => $offer->damage_zones ? implode(', ', array_map(fn ($z) => DamageZone::labelOf($z), $offer->damage_zones)) : null,
            'На ходу' => $offer->is_runnable === null ? null : ($offer->is_runnable ? 'Да' : 'Нет'),
            'Ключи' => $offer->has_keys === null ? null : ($offer->has_keys ? 'Есть' : 'Нет'), 'Документы' => $offer->papers?->label(),
            'Город' => $offer->settlement?->title(), 'Осмотр' => $offer->show_address || $fullVin ? $offer->inspection_address : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
