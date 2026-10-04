<?php

namespace App\Offers;

use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\Colors;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Settlement;
use App\Cars\Transmission;
use App\Support\Liters;
use Illuminate\Support\Facades\DB;

/**
 * Где наше поле не совпадает с лотом Мигторга. Наше важнее (владелец, 04.10.2026): лот пишет только в пустые поля, а
 * разница — знаком «!» у подписи поля, нажатие показывает, как на Мигторге (`migtorg_diff_controller`). Сравнивается
 * то же, что лот умеет заполнить (`MigtorgFields`); пустое у нас — не разница, его лот и так заполнит.
 */
final class MigtorgDiff
{
    /** @return array<string, string> имя поля формы → как на Мигторге */
    public static function of(Offer $offer): array
    {
        if (! $offer->claim_ref_key) {
            return [];
        }
        $data = DB::table('migtorg_lots')->where('claim_ref_key', $offer->claim_ref_key)->whereNotNull('data')->orderByDesc('id')->value('data');
        $lot = $data ? MigtorgFields::of(json_decode($data, true)) : [];
        $v = fn (string $field) => $lot[$field]['value'] ?? null;
        $out = [];
        $put = function (string $name, bool $differs, ?string $text) use (&$out) {
            if ($differs && $text !== null && $text !== '') {
                $out[$name] = $text;
            }
        };

        if ($offer->brand_id && ($brand = $v('brand'))) {
            $put('brand_id', Brand::known($brand)?->id !== $offer->brand_id, $brand);
        }
        $sameBrand = $offer->brand_id && $v('brand') && Brand::known($v('brand'))?->id === $offer->brand_id;
        if ($offer->model_id && ($model = $v('model'))) {
            $put('model_id', ! $sameBrand || mb_strtolower((string) $offer->model?->name) !== mb_strtolower($model), $model);
        }
        foreach (['year', 'mileage', 'engine_power'] as $field) {
            if ($offer->{$field} && $v($field)) {
                $put($field, (int) $offer->{$field} !== (int) $v($field), $field === 'mileage' ? number_format((int) $v($field), 0, ',', ' ').' км' : (string) $v($field));
            }
        }
        if ($offer->engine_volume && $v('engine_volume')) {
            $put('engine_volume', (int) $offer->engine_volume !== (int) $v('engine_volume'), Liters::format((int) $v('engine_volume')).' л');
        }
        if ($offer->vin && $v('vin')) {
            $put('vin', strtoupper($offer->vin) !== $v('vin'), $v('vin'));
        }
        if ($offer->color && $v('color')) {
            $put('color', mb_strtolower(Colors::normalize($offer->color) ?? $offer->color) !== mb_strtolower(Colors::normalize($v('color')) ?? $v('color')), $v('color'));
        }
        foreach (['transmission' => Transmission::class, 'drive' => Drive::class, 'fuel' => Fuel::class, 'body' => Body::class] as $field => $enum) {
            if ($offer->{$field} && $v($field)) {
                $put($field, $offer->{$field}->value !== $v($field), $enum::from($v($field))->label());
            }
        }
        if ($offer->settlement_id && ($city = $v('city'))) {
            $put('settlement_id', (Settlement::named($city)['id'] ?? null) !== $offer->settlement_id, $city);
        }

        return $out;
    }
}
