<?php

namespace App\Offers;

use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Identity;
use App\Cars\Vin\Vin;
use App\Cars\Colors;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Settlement;
use App\Cars\Transmission;
use App\Offers\Actions\UpdateOffer;
use App\Support\Liters;
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
        $rejected = (array) $offer->migtorg_rejected;

        return array_filter(self::all($offer), fn ($text, $name) => ($rejected[$name] ?? null) !== $text, ARRAY_FILTER_USE_BOTH);
    }

    /** Взять значение Мигторга в поле. false — поля уже нет среди разниц или значение не прошло. */
    public static function take(Offer $offer, string $name, ?User $by): bool
    {
        $text = self::of($offer)[$name] ?? null;
        if ($text === null) {
            return false;
        }
        $lot = self::lot($offer);
        $v = fn (string $field) => $lot[$field]['value'] ?? null;
        $data = match ($name) {
            'brand_id' => ['brand_id' => Brand::resolve((string) $v('brand'))->id]
                + ($v('model') ? ['model_id' => CarModel::resolve(Brand::resolve((string) $v('brand')), (string) $v('model'))->id] : ['model_id' => null]),
            'model_id' => $offer->brand ? ['model_id' => CarModel::resolve($offer->brand, (string) $v('model'))->id] : [],
            'year', 'mileage', 'engine_power', 'engine_volume' => [$name => (int) $v($name)],
            'vin' => ($vin = Vin::full((string) $v('vin'))) && ! Identity::offerByVin($vin, $offer->id) ? ['vin' => $vin] : [],
            'color' => ['color' => Colors::normalize((string) $v('color')) ?? (string) $v('color')],
            'transmission', 'drive', 'fuel', 'body' => [$name => (string) $v($name)],
            'settlement_id' => ($id = Settlement::named((string) $v('city'))['id'] ?? null) ? ['settlement_id' => $id] : [],
            default => [],
        };
        if (! $data) {
            return false;
        }
        $rejected = (array) $offer->migtorg_rejected;
        unset($rejected[$name]);
        $offer->forceFill(['migtorg_rejected' => $rejected ?: null]);
        app(UpdateOffer::class)($offer, $data, $by);
        $offer->log(OfferEventType::Note, $by, ['text' => "Взято с Мигторга: {$text}"]);

        return true;
    }

    public static function reject(Offer $offer, string $name, ?User $by): bool
    {
        $text = self::of($offer)[$name] ?? null;
        if ($text === null) {
            return false;
        }
        $offer->forceFill(['migtorg_rejected' => [...(array) $offer->migtorg_rejected, $name => $text]])->save();
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
