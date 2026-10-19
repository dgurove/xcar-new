<?php

namespace App\Cars;

use App\Cars\Vin\Vin;
use App\Offers\Offer;
use App\Support\Liters;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Поля машины одним описанием на CRM и парковку (19.10.2026, владелец: «ТС одна сущность и в парковке и в CRM… один
 * модуль кода и стиля, одна таблица, одинаковые поля; в парковке просто другие блоки»). Машина — `Cars\Car`, дорожки
 * пишут её через `OnCar`; здесь — какими блоками её показывают (`x-car.blocks`), как подписаны поля, как их проверяют и
 * чистят. Имя поля в форме — имя дорожки для колонки машины (`OnCar::carAliases`: у парковки номер убытка `ref`,
 * страхователь `contact_*`, у продажи тип ТС `vehicle_category`), подпись и правило — одни.
 *
 * Блоки дорожки, которые не машина, — у дорожки: у продажи «Осмотр» (город, адрес, описание) и «Цены», у парковки
 * «Хранение» (оценочная и своя ставка).
 */
final class CarFields
{
    /**
     * Поле: подпись и вид ввода. `code` — номер (заглавными, копируется), `int` — число, `digits` — с разрядами, `enum` —
     * список значений, `tel` — телефон, `brand`/`model` — справочники рядом, `vendor` — вендор своей стороны.
     */
    public const FIELDS = [
        'vendor_id' => ['Вендор', 'vendor'],
        'claim_ref' => ['Номер убытка', 'code'],
        'vin' => ['VIN', 'vin'],
        'brand_id' => ['Марка', 'brand'],
        'model_id' => ['Модель', 'model'],
        'year' => ['Год', 'int'],
        'color' => ['Цвет', 'text'],
        'category' => ['Тип ТС', 'enum', Category::class],
        'mileage' => ['Пробег, км', 'digits'],
        'plate' => ['Госномер', 'code'],
        'pts' => ['ПТС или ЭПТС', 'code'],
        'sts' => ['СТС', 'code'],
        'policy_no' => ['№ полиса', 'code'],
        'body_no' => ['Кузов №', 'code'],
        'chassis_no' => ['Шасси (рама) №', 'code'],
        'body' => ['Кузов', 'enum', Body::class],
        'transmission' => ['Коробка', 'enum', Transmission::class],
        'drive' => ['Привод', 'enum', Drive::class],
        'fuel' => ['Топливо', 'enum', Fuel::class],
        'engine_volume' => ['Объём, л', 'liters'],
        'engine_power' => ['Мощность, л. с.', 'int'],
        'insured_name' => ['Страхователь', 'text'],
        'insured_phone' => ['Телефон', 'tel'],
    ];

    /** Блоки машины по порядку: заголовок и поля. Какие блоки у дорожки — решает её экран. */
    public const BLOCKS = [
        'car' => ['Транспортное средство', ['vendor_id', 'claim_ref', 'vin', 'brand_id', 'model_id', 'year', 'color', 'category', 'mileage']],
        'papers' => ['ПТС и СТС', ['plate', 'pts', 'sts', 'policy_no', 'body_no', 'chassis_no']],
        'specs' => ['Характеристики', ['body', 'transmission', 'drive', 'fuel', 'engine_volume', 'engine_power']],
        'owner' => ['Страхователь', ['insured_name', 'insured_phone']],
    ];

    /** Коды пишутся заглавными без пробелов по краям: «ам 1234» и «АМ 1234» — один номер. */
    private const CODES = ['pts', 'sts', 'policy_no', 'body_no', 'chassis_no'];

    /** Имя поля в форме дорожки: своё имя колонки машины, если дорожка зовёт её иначе (`ref`, `contact_name`). */
    public static function name(string $lane, string $column): string
    {
        return array_flip(self::aliases($lane))[$column] ?? $column;
    }

    /** Колонка машины по имени поля дорожки. */
    public static function column(string $lane, string $name): string
    {
        return self::aliases($lane)[$name] ?? $name;
    }

    /** @return list<string> имена полей блока в форме дорожки */
    public static function names(string $lane, string ...$blocks): array
    {
        return collect($blocks)->flatMap(fn ($b) => self::BLOCKS[$b][1])->map(fn ($c) => self::name($lane, $c))->values()->all();
    }

    public static function label(string $column, ?object $lane = null): string
    {
        // У лизинга номер — договора лизинга.
        if ($column === 'claim_ref' && $lane instanceof Offer && $lane->leaseRef()) {
            return 'Номер ДЛ';
        }

        return self::FIELDS[$column][0];
    }

    public static function kind(string $column): string
    {
        return self::FIELDS[$column][1];
    }

    /** @return class-string|null */
    public static function enum(string $column): ?string
    {
        return self::FIELDS[$column][2] ?? null;
    }

    /**
     * Правила полей машины именами дорожки. Обязательность — у дорожки: черновику предложения нужны вендор и номер,
     * парковке — марка и модель (`VehicleFields`, `OfferRequest`).
     *
     * @param  list<string>  $blocks  какие блоки (пусто — все)
     * @return array<string, list<mixed>>
     */
    public static function rules(string $lane, array $blocks = []): array
    {
        $rules = [
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'claim_ref' => ['nullable', 'string', 'max:60'],
            'vin' => ['nullable', 'string', 'size:17'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'model_id' => ['nullable', 'exists:car_models,id'],
            'year' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            'color' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', Rule::enum(Category::class)],
            'mileage' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'plate' => ['nullable', 'string', 'max:12'],
            'pts' => ['nullable', 'string', 'max:40'],
            'sts' => ['nullable', 'string', 'max:40'],
            'policy_no' => ['nullable', 'string', 'max:60'],
            'body_no' => ['nullable', 'string', 'max:40'],
            'chassis_no' => ['nullable', 'string', 'max:40'],
            'body' => ['nullable', Rule::enum(Body::class)],
            'transmission' => ['nullable', Rule::enum(Transmission::class)],
            'drive' => ['nullable', Rule::enum(Drive::class)],
            'fuel' => ['nullable', Rule::enum(Fuel::class)],
            'engine_volume' => ['nullable', 'integer', 'max:20000'],
            'engine_power' => ['nullable', 'integer', 'max:3000'],
            'insured_name' => ['nullable', 'string', 'max:80'],
            'insured_phone' => ['nullable', 'string', 'max:20'],
        ];
        $columns = $blocks ? collect($blocks)->flatMap(fn ($b) => self::BLOCKS[$b][1])->all() : array_keys($rules);

        return collect($columns)->mapWithKeys(fn ($c) => [self::name($lane, $c) => $rules[$c]])->all();
    }

    /**
     * Чистка до проверки, одна на обе стороны: VIN одной записью (кириллица того же начертания — латиница), цвет со
     * словаря без исправления опечаток, коды заглавными, пробег и мощность цифрами, объём литрами.
     */
    public static function clean(Request $request, string $lane, ?int $volumeWas = null): void
    {
        $name = fn (string $c) => self::name($lane, $c);
        $merge = [];
        if ($request->has('vin')) {
            $merge['vin'] = Vin::normalize((string) $request->input('vin'));
        }
        if ($request->filled('color')) {
            $merge['color'] = Colors::normalize($request->input('color'), fuzzy: false) ?? trim((string) $request->input('color'));
        }
        foreach (self::CODES as $c) {
            if ($request->filled($name($c))) {
                $merge[$name($c)] = mb_strtoupper(trim((string) $request->input($name($c))));
            }
        }
        foreach (['mileage', 'engine_power'] as $c) {
            if ($request->has($c)) {
                $merge[$c] = Money::rubles($request->input($c));
            }
        }
        if ($request->has('engine_volume')) {
            $merge['engine_volume'] = Liters::parse($request->input('engine_volume'), $volumeWas);
        }
        $request->merge($merge);
    }

    /** @return array<string, string> имя дорожки → колонка машины */
    private static function aliases(string $lane): array
    {
        return method_exists($lane, 'carAliases') ? $lane::carAliases() : [];
    }
}
