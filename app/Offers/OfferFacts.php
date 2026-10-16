<?php

namespace App\Offers;

use App\Cars\DamageZone;
use App\Support\Units;

/**
 * «Характеристики» ТС словами — одна дверь на витрину, страницу ТС в гараже, вывоз и сделку менеджера.
 * $fullVin — VIN целиком (тем, кто с машиной работает), иначе как этому человеку положено (`Offer::vinFor`).
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
            'Пробег' => Units::km($offer->mileage),
            'Кузов' => $offer->body?->label(), 'КПП' => $offer->transmission?->label(), 'Привод' => $offer->drive?->label(),
            'Топливо' => $offer->fuel?->label(), 'Объём' => Units::liters($offer->engine_volume),
            'Мощность' => Units::hp($offer->engine_power), 'Цвет' => $offer->color,
            'VIN' => $fullVin ? $offer->vin : $offer->vinFor(auth()->user()), 'Причина' => $offer->damage_cause?->label(),
            'Повреждения' => $offer->damage_zones ? implode(', ', array_map(fn ($z) => DamageZone::labelOf($z), $offer->damage_zones)) : null,
            'На ходу' => $offer->is_runnable === null ? null : ($offer->is_runnable ? 'Да' : 'Нет'),
            'Ключи' => $offer->has_keys === null ? null : ($offer->has_keys ? 'Есть' : 'Нет'), 'Документы' => $offer->papers?->label(),
            'Город' => $offer->settlement?->title(), 'Осмотр' => $offer->show_address || $fullVin ? \App\Support\VendorNames::strip($offer->inspection_address) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
