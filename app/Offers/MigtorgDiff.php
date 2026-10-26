<?php

namespace App\Offers;

use App\Cars\Actions\FillCar;
use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\Colors;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Rejections;
use App\Cars\Settlement;
use App\Cars\Transmission;
use App\Support\Units;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Где наше поле не совпадает с лотом Мигторга. Наше важнее (владелец, 04.10.2026): лот пишет только в пустые поля, а
 * разница — знаком «!» у подписи поля, нажатие показывает, как на Мигторге (`migtorg_diff_controller`). Сравнивается
 * то же, что лот умеет заполнить (`MigtorgFields`); пустое у нас — не разница, его лот и так заполнит. У «!» —
 * «Взять» (`take`) и «Отклонить» (`reject`, 06.10.2026: Мигторг бывает неправ): отклонённый текст поля больше не
 * показывается, другой текст того же поля — снова.
 */
final class MigtorgDiff
{
    /** @return array<string, string> имя поля формы → как на Мигторге */
    public static function of(Offer $offer): array
    {
        $rejected = Rejections::of(Rejections::car($offer->car_id), 'migtorg');

        return array_filter(self::all($offer), fn ($text, $name) => ! in_array($text, $rejected[$name] ?? [], true), ARRAY_FILTER_USE_BOTH);
    }

    /** Взять значение Мигторга в поле. false — поля уже нет среди разниц или значение не прошло. */
    public static function take(Offer $offer, string $name, ?User $by): bool
    {
        $text = self::of($offer)[$name] ?? null;
        if ($text === null) {
            return false;
        }
        // Поле разницы → поля лота: марка — с моделью, город — `city`. Записывает дверь `FillCar` (выбор человека — поверх).
        $from = match ($name) {
            'brand_id' => ['brand', 'model'],
            'model_id' => ['model'],
            'settlement_id' => ['city'],
            default => [$name],
        };
        $chosen = array_intersect_key(self::lot($offer), array_flip($from));
        // Город — только найденный в справочнике: устаревший или кривой город лота нового пункта не заводит.
        if (isset($chosen['city'])) {
            unset($chosen['city']['create']);
        }
        if (! $chosen) {
            return false;
        }
        if (! app(FillCar::class)($offer, $chosen, $by, FillCar::CHOSEN)) {
            return false;
        }
        Rejections::forget(Rejections::car($offer->car_id), 'migtorg', $name);
        $offer->log(OfferEventType::Note, $by, ['text' => "Взято с Мигторга: {$text}"]);

        return true;
    }

    public static function reject(Offer $offer, string $name, ?User $by): bool
    {
        $text = self::of($offer)[$name] ?? null;
        if ($text === null) {
            return false;
        }
        Rejections::add(Rejections::car($offer->car_id), 'migtorg', $name, $text, $by);
        $offer->log(OfferEventType::Note, $by, ['text' => "Мигторг отклонён: {$text}"]);

        return true;
    }

    /** @return array<string, array{value: mixed}> */
    private static function lot(Offer $offer): array
    {
        $data = DB::table('migtorg_lots')->where('claim_ref_key', $offer->claim_ref_key)->whereNotNull('data')->orderByDesc('id')->value('data');

        return $data ? MigtorgFields::of(json_decode($data, true)) : [];
    }

    /** @return array<string, string> */
    private static function all(Offer $offer): array
    {
        if (! $offer->claim_ref_key) {
            return [];
        }
        $lot = self::lot($offer);
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
                $put($field, (int) $offer->{$field} !== (int) $v($field), $field === 'mileage' ? Units::km($v($field)) : (string) $v($field));
            }
        }
        if ($offer->engine_volume && $v('engine_volume')) {
            $put('engine_volume', (int) $offer->engine_volume !== (int) $v('engine_volume'), Units::liters($v('engine_volume')));
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
            $put('settlement_id', (Settlement::resolve($city, $lot['city']['region'] ?? null)['id'] ?? null) !== $offer->settlement_id, $city);
        }

        return $out;
    }
}
