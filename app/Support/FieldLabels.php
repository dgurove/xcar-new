<?php

namespace App\Support;

use App\Billing\Cadence;
use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Category;
use App\Cars\DamageCause;
use App\Cars\DamageZone;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Papers;
use App\Cars\Settlement;
use App\Cars\Transmission;
use App\Offers\CarPlace;
use App\Offers\Flag;
use App\Park\Yard;
use App\Vendors\DocRequirement;
use App\Vendors\Vendor;
use Illuminate\Support\Carbon;

/**
 * Имена полей и значения по-русски для ленты «Изменён: закупочная цена 183 840 → 211 200 ₽» — в истории не должно быть
 * имён колонок и сырых значений (`Changes`).
 */
final class FieldLabels
{
    /** Деньги в рублях: «183 840 → 211 200 ₽». */
    private const MONEY = ['floor_price', 'owner_price', 'deal_owner_price', 'value', 'publish_price', 'asking_price', 'min_bid_price', 'assigned_price', 'cost', 'supplier_cost', 'amount', 'commission', 'share'];

    /** Поле → перечисление с `label()`. */
    private const ENUMS = ['body' => Body::class, 'transmission' => Transmission::class, 'drive' => Drive::class, 'fuel' => Fuel::class,
        'damage_cause' => DamageCause::class, 'papers' => Papers::class, 'category' => Category::class, 'billing_cadence' => Cadence::class, 'car_place' => CarPlace::class];

    /** Списки значений перечислений. */
    private const LISTS = ['damage_zones' => DamageZone::class, 'flags' => Flag::class, 'docs_required' => DocRequirement::class];

    /** Ссылки — именем записи. */
    private const NAMES = ['brand_id' => [Brand::class, 'name'], 'model_id' => [CarModel::class, 'name'], 'vendor_id' => [Vendor::class, 'name'], 'yard_id' => [Yard::class, 'name']];

    private const LABELS = [
        'ref' => 'номер убытка', 'claim_ref' => 'номер убытка', 'vin' => 'VIN', 'show_vin' => 'показ VIN', 'show_address' => 'показ адреса', 'plate' => 'госномер', 'year' => 'год', 'color' => 'цвет',
        'brand_id' => 'марка', 'model_id' => 'модель', 'mileage' => 'пробег', 'body' => 'кузов', 'transmission' => 'КПП', 'drive' => 'привод', 'fuel' => 'топливо',
        'engine_volume' => 'объём двигателя', 'engine_power' => 'мощность', 'damage_cause' => 'причина повреждений', 'damage_zones' => 'повреждения', 'damage_note' => 'что заметили',
        'is_runnable' => 'на ходу', 'has_keys' => 'ключи', 'papers' => 'документы', 'incident_date' => 'дата события', 'description' => 'описание',
        'car_place' => 'где ТС', 'settlement_id' => 'город', 'inspection_address' => 'адрес осмотра', 'floor_price' => 'закупочная цена', 'owner_price' => 'собственнику по ДКП', 'value' => 'оценочная стоимость', 'publish_price' => 'заявленная цена', 'asking_price' => 'цена продажи',
        'min_bid_price' => 'минимальная цена', 'min_bid_share' => 'доля от цены', 'prices_include_vat' => 'НДС', 'tags' => 'метки', 'tag_colors' => 'цвета меток', 'bids_close_at' => 'срок приёма',
        'chat_enabled' => 'чат', 'share_locked' => 'запрет шеринга', 'recommended' => 'рекомендуем', 'managers_limited' => 'круг менеджеров', 'managers' => 'менеджеры', 'audience_rules' => 'кому показывать', 'audience_id' => 'шаблон показа', 'deal_audience' => 'кому показывать в сделке', 'deal_show_until' => 'сколько показывать в сделке',
        'vendor_id' => 'вендор', 'answer_by' => 'ответ до', 'insured_name' => 'страхователь', 'insured_phone' => 'телефон страхователя', 'insured_phone2' => 'ещё телефон страхователя', 'flags' => 'признаки', 'holder' => 'держатель',
        'contact_name' => 'контакт', 'contact_phone' => 'телефон', 'contact_email' => 'почта ответственного', 'insurer_deadline_at' => 'срок от вендора', 'binding_until' => 'срок обязывающего предложения',
        'category' => 'категория', 'oversize' => 'негабарит', 'value' => 'оценочная стоимость', 'policy_no' => '№ полиса', 'contract_kind' => 'основание', 'contract_no' => 'номер договора', 'contract_at' => 'дата договора',
        'assigned_price' => 'назначенная цена', 'pts' => 'ПТС', 'sts' => 'СТС', 'body_no' => 'номер кузова', 'chassis_no' => 'номер шасси', 'owner_party_id' => 'комитент', 'storage_rate' => 'своя ставка', 'storage_rate_note' => 'почему своя', 'billing_cadence' => 'счёт за хранение', 'sold_at' => 'продано', 'pickup_name' => 'кто заберёт', 'pickup_phone' => 'телефон покупателя',
        'cost' => 'закупочная в сделке', 'supplier_cost' => 'закупочная поставщика', 'deal_owner_price' => 'собственнику в сделке', 'amount' => 'цена подтверждения', 'commission' => 'вознаграждение', 'share' => 'наша доля',
        'notes' => 'заметки', 'yard_id' => 'парковка', 'spot' => 'место', 'docs_required' => 'документы вендору', 'state' => 'состояние', 'offer_id' => 'предложение',
    ];

    /** @param  list<string>  $fields */
    public static function list(array $fields): string
    {
        return implode(', ', array_map(self::label(...), $fields));
    }

    public static function label(string $field): string
    {
        return self::LABELS[$field] ?? str_replace('_', ' ', $field);
    }

    public static function isMoney(string $field): bool
    {
        return in_array($field, self::MONEY, true);
    }

    /** Значение поля словами: деньги в рублях, марка именем, перечисление подписью, дата — «14 окт 2026». Пусто — null. */
    public static function value(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (self::isMoney($field)) {
            return Money::rub((int) $value);
        }
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }
        if (isset(self::ENUMS[$field])) {
            return self::ENUMS[$field]::tryFrom((string) $value)?->label() ?? (string) $value;
        }
        if (isset(self::LISTS[$field]) && is_array($value)) {
            return implode(', ', array_map(fn ($v) => self::LISTS[$field]::tryFrom((string) $v)?->label() ?? (string) $v, $value));
        }
        if (isset(self::NAMES[$field])) {
            [$class, $column] = self::NAMES[$field];

            return (string) ($class::withoutGlobalScopes()->whereKey($value)->value($column) ?? $value);
        }
        if ($field === 'settlement_id') {
            return Settlement::find($value)?->title() ?? (string) $value;
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $value));
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $value)) {
            $at = Carbon::parse($value);

            return Ago::day($at, strlen($value) > 10);
        }
        if ($field === 'min_bid_share') {
            return str_replace('.', ',', (string) $value);
        }
        if (is_numeric($value)) {
            return match ($field) {
                'mileage' => Units::km($value) ?? (string) $value,
                'engine_volume' => Units::liters($value) ?? (string) $value,
                'engine_power' => Units::hp($value) ?? (string) $value,
                default => (string) $value,
            };
        }

        return (string) $value;
    }
}
