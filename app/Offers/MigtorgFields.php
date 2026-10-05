<?php

namespace App\Offers;

use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Papers;
use App\Cars\Transmission;
use App\Cars\Vin\Vin;
use Illuminate\Support\Carbon;

/**
 * Характеристики лота Мигторга → поля предложения (`ApplyCarFields`, только в пустые). Коды — их API («MECHANICAL»,
 * «four_wheel»), «noValue» — пусто. Сверено по 442 живым лотам 04.10.2026. Не берутся: описание (их условия торгов —
 * не для наших менеджеров), страховая (название вразнобой, вендор у предложения свой), руль и владельцы (полей нет),
 * «Универсал» (стоит у 41 % лотов, даже у Lotus Eletre — у них это значение по умолчанию), цены и ставки торгов.
 */
final class MigtorgFields
{
    private const TRANSMISSION = ['AUTOMATIC' => Transmission::Automatic, 'MECHANICAL' => Transmission::Manual, 'VARIATOR' => Transmission::Cvt, 'ROBOT' => Transmission::DualClutch];

    private const DRIVE = ['front_wheel' => Drive::Front, 'rear_drive' => Drive::Rear, 'four_wheel' => Drive::All];

    private const FUEL = ['fuel' => Fuel::Petrol, 'diesel' => Fuel::Diesel, 'hybrid' => Fuel::Hybrid, 'electro' => Fuel::Electric];

    private const BODY = [
        'седан' => Body::Sedan, 'хетчбек' => Body::Hatchback, 'хэтчбек' => Body::Hatchback, 'внедорожник' => Body::Suv,
        'кроссовер' => Body::Crossover, 'купе' => Body::Coupe, 'минивен' => Body::Minivan, 'пикап' => Body::Pickup,
        'фургон' => Body::Van, 'грузовик' => Body::Truck, 'седельный тягач' => Body::Truck, 'автобус' => Body::Bus,
        'спецтехника' => Body::Special, 'мотоцикл' => Body::Moto,
    ];

    /** @return array<string, array{value: mixed}> поле → значение, как у окна «✨» */
    public static function of(array $auction): array
    {
        $lot = $auction['lot'] ?? [];
        $f = [];
        $put = function (string $field, mixed $value) use (&$f) {
            if ($value !== null && $value !== '') {
                $f[$field] = ['value' => $value];
            }
        };
        // Марка — из их справочника (у неё id на Мигторге): нашей нет — заводится (05.10.2026: JELAND J6 вставал «ТС»).
        // «JELAND» капслоком — «Jeland», как прочие марки справочника; короткие («BMW», «FAW») — как есть.
        $brand = trim((string) ($lot['brand']['title'] ?? ''));
        if ($brand !== '' && ! Brand::known($brand) && preg_match('/\p{L}{2}/u', $brand)) {
            $f['brand'] = ['value' => mb_strlen($brand) > 3 && $brand === mb_strtoupper($brand) ? mb_convert_case($brand, MB_CASE_TITLE) : $brand, 'create' => true];
        } else {
            $put('brand', $brand);
        }
        $put('model', trim((string) ($lot['model']['title'] ?? '')));
        $put('year', ($lot['year'] ?? 0) ?: null);
        // Мигторг прячет VIN звёздочками — маска VIN не даёт (`Vin::full`).
        $put('vin', Vin::full((string) ($lot['vin'] ?? '')));
        $put('color', trim((string) ($lot['color']['title'] ?? '')));
        $put('mileage', ($lot['mileage'] ?? 0) ?: null);
        $put('transmission', (self::TRANSMISSION[strtoupper((string) ($lot['transmission'] ?? ''))] ?? null)?->value);
        $put('drive', (self::DRIVE[$lot['gear'] ?? ''] ?? null)?->value);
        $put('fuel', (self::FUEL[$lot['engine_type'] ?? ''] ?? null)?->value);
        $put('engine_volume', ($lot['engine_volume'] ?? 0) ?: null);
        // В списке мощности нет; карточка со входом может отдать — имя поля не знаем заранее.
        $put('engine_power', ($lot['engine_power'] ?? $lot['power'] ?? $lot['horse_power'] ?? 0) ?: null);
        $put('body', (self::BODY[mb_strtolower(trim((string) ($lot['carcass']['title'] ?? '')))] ?? null)?->value);
        $put('city', trim((string) ($lot['city']['title'] ?? '')));
        $keys = $lot['keys_count'] ?? null;
        $put('has_keys', $keys === null ? null : (int) $keys > 0);
        $put('papers', ($lot['pts'] ?? null) === 'present' ? Papers::Pts->value : null);
        // Конец торгов — срок страховой: до него она ждёт цену (как «ответить до» из письма); прошедший — не срок.
        $end = isset($auction['end_date']) ? Carbon::parse($auction['end_date'], 'Europe/Moscow') : null;
        $put('deadline', $end?->isFuture() ? $end->toDateTimeString() : null);

        return $f;
    }
}
