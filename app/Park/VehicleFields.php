<?php

namespace App\Park;

use App\Cars\Category;
use Illuminate\Validation\Rule;

/**
 * Поля тождества ТС — те, что приходят в письме: убыток, вендор, марка, модель, год, госномер, VIN,
 * категория, страхователь с телефоном, оценка. Одна форма на дело и на новую заявку, одни правила.
 * Цвет, негабарит, повреждения и показания не спрашиваются — осмотр будет отдельным модулем.
 */
final class VehicleFields
{
    public const KEYS = ['ref', 'vendor_id', 'brand_id', 'model_id', 'year', 'plate', 'vin', 'category', 'contact_name', 'contact_phone', 'value'];

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'ref' => ['nullable', 'string', 'max:60'], 'vin' => ['nullable', 'string', 'max:17'], 'plate' => ['nullable', 'string', 'max:12'],
            'year' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            // Марка и модель обязательны: ТС без имени в списках не нужна, номер убытка человеку ничего не говорит.
            'brand_id' => ['required', 'exists:brands,id'], 'model_id' => ['required', 'exists:car_models,id'], 'vendor_id' => ['nullable', 'exists:vendors,id'],
            'category' => ['nullable', Rule::enum(Category::class)],
            'contact_name' => ['nullable', 'string', 'max:80'], 'contact_phone' => ['nullable', 'string', 'max:20'], 'value' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** Только поля ТС из проверенных данных формы — этап заявки шлёт их вместе со своими. */
    public static function only(array $data): array
    {
        return array_intersect_key($data, array_flip(self::KEYS));
    }
}
