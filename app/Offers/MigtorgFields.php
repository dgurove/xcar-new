<?php

namespace App\Offers;

use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\Category;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Papers;
use App\Cars\Transmission;
use App\Cars\Vin\Vin;
use Illuminate\Support\Carbon;

/**
 * Характеристики лота Мигторга → поля предложения (`Cars\Actions\FillCar`, только в пустые). Коды — их API («MECHANICAL»,
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

    /**
     * Тип ТС, которого кузов не скажет (02.11.2026, Z691/046/13687/26 «Граз» с кузовом «Прицеп» вставал легковым):
     * прицеп и тягач — свои типы стоянки, у кузова их нет.
     */
    private const CARCASS_CATEGORY = ['прицеп' => Category::Trailer, 'полуприцеп' => Category::Trailer, 'седельный тягач' => Category::Long,
        'эвакуатор' => Category::Truck, 'электровелосипед' => Category::Moto];

    /**
     * Тип марки в их справочнике (`brand.car_type_id`) — когда кузова нет или он «Универсал». Сверено по 18 тыс. лотов
     * 02.11.2026: 1 — легковые (решает кузов), 3 — тягачи, 4 и 27 — прицепы, 5 и 32 — грузовые, 6 и 376 — фургоны и
     * микроавтобусы, 20, 22, 23 — мото и квадроциклы, 31, 596, 980 — спецтехника.
     */
    private const TYPE_CATEGORY = [3 => Category::Long, 4 => Category::Trailer, 27 => Category::Trailer, 5 => Category::Truck,
        32 => Category::Truck, 6 => Category::Light, 376 => Category::Light, 20 => Category::Moto, 22 => Category::Moto,
        23 => Category::Moto, 31 => Category::Special, 596 => Category::Special, 980 => Category::Special];

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
        $carcass = mb_strtolower(trim((string) ($lot['carcass']['title'] ?? '')));
        $body = self::BODY[$carcass] ?? null;
        $put('body', $body?->value);
        $put('category', self::category($carcass, $body, (int) ($lot['brand']['car_type_id'] ?? 0), trim($brand.' '.($lot['model']['title'] ?? '')))?->value);
        // Город — с регионом: сёла и одинаковые имена без него не найти (`Settlement::resolve`).
        if ($city = trim((string) ($lot['city']['title'] ?? ''))) {
            // Справочник его не знает — текстом как есть (`create`, как у марки): у лота город — своё поле, не догадка.
            $f['city'] = ['value' => $city, 'region' => trim((string) ($lot['city']['region']['title'] ?? '')) ?: null, 'create' => true];
        }
        $keys = $lot['keys_count'] ?? null;
        $put('has_keys', $keys === null ? null : (int) $keys > 0);
        $put('papers', ($lot['pts'] ?? null) === 'present' ? Papers::Pts->value : null);
        // Конец торгов — срок страховой: до него она ждёт цену (как «ответить до» из письма); прошедший — не срок.
        $end = isset($auction['end_date']) ? Carbon::parse($auction['end_date'], 'Europe/Moscow') : null;
        $put('deadline', $end?->isFuture() ? $end->toDateTimeString() : null);

        return $f;
    }

    /**
     * Прицеп и тягач по кузову — точно; дальше наша догадка по названию (она знает «Луидор» и «Krone», у Мигторга это
     * «Грузовик» и «Спецтехника»), дальше их кузов, дальше тип марки.
     */
    private static function category(string $carcass, ?Body $body, int $type, string $title): ?Category
    {
        return self::CARCASS_CATEGORY[$carcass] ?? Category::guess($title) ?? ($carcass !== 'универсал' ? $body?->category() : null) ?? self::TYPE_CATEGORY[$type] ?? null;
    }
}
