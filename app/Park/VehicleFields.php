<?php

namespace App\Park;

use App\Cars\CarFields;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Поля ТС на парковке — те же блоки машины, что в CRM (`Cars\CarFields`, 19.10.2026: «ТС одна сущность»): «Транспортное
 * средство», «ПТС и СТС», «Страхователь», и своё парковки — оценочная стоимость. Одна форма на дело и на новую заявку,
 * одни правила. Негабарит, повреждения и показания не спрашиваются — осмотр будет отдельным модулем.
 */
final class VehicleFields
{
    /** Блоки машины на парковке. */
    public const BLOCKS = ['car', 'papers', 'owner'];

    /** @return list<string> имена полей ТС в форме парковки */
    public static function keys(): array
    {
        return [...CarFields::names(Vehicle::class, ...self::BLOCKS), 'value'];
    }

    /**
     * Марка и модель обязательны: ТС без имени в списках не нужна, номер убытка человеку ничего не говорит.
     * `$identity = false` — форма без полей ТС (шторка «Условия» шлёт только договор).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $identity = true): array
    {
        $name = $identity ? 'required' : 'nullable';

        return [...CarFields::rules(Vehicle::class, self::BLOCKS),
            'brand_id' => [$name, 'exists:brands,id'], 'model_id' => [$name, 'exists:car_models,id'],
            'value' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** Чистка машины — общая с CRM (`CarFields::clean`); оценочная и своя ставка набираются разрядами. */
    public static function clean(Request $request): void
    {
        CarFields::clean($request, Vehicle::class);
        if ($request->has('value')) {
            $request->merge(['value' => Money::rubles((string) $request->input('value'))]);
        }
        // Своя ставка набирается разрядами и с запятой («1 200», «120,5»).
        if ($request->has('storage_rate')) {
            $rate = str_replace([' ', ' ', ','], ['', '', '.'], (string) $request->input('storage_rate'));
            $request->merge(['storage_rate' => $rate === '' ? null : $rate]);
        }
    }

    /** Только поля ТС из проверенных данных формы — этап заявки шлёт их вместе со своими. */
    public static function only(array $data): array
    {
        return array_intersect_key($data, array_flip(self::keys()));
    }
}
