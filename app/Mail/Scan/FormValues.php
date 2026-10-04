<?php

namespace App\Mail\Scan;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Colors;
use App\Cars\Settlement;
use App\Mail\Extraction\ScanFields;
use App\Support\Liters;

/**
 * Найденное в документах (`ScanFields::found`) — в именах и видах полей формы, куда его кладёт читалка «Завести»
 * (`x-mail.reader`): разбор письма парковки и редактор предложения. Как в `ApplyCarFields`: марка и модель по
 * справочнику (модель документа под известной маркой заводится, как у текста про машину), цвет словарём, VIN
 * заглавными, объём литрами, город — местом справочника. Марки, которой нет в справочнике, нет и в форме.
 *
 * Поле → подпись, имя в форме и варианты в порядке файлов: у обычного — `value`, у комбобокса — `id` и `text`,
 * у машины — `brand` и `model` (`{id, text}`), у всех — `text` для строки расхождения и `from` (откуда).
 */
final class FormValues
{
    private const NAMES = ['city' => 'settlement_id'];

    /** @param list<string> $fields  поля предмета (`Subject::fields`) */
    public static function of(array $found, array $fields): array
    {
        $labels = array_intersect_key(ScanFields::LABELS + ScanFields::SPECS, array_flip($fields));
        $out = [];
        foreach ($labels as $field => $label) {
            $options = [];
            foreach ($found[$field] ?? [] as $option) {
                if ($row = self::option($field, $option['value'], $option['text'])) {
                    $options[] = [...$row, 'from' => $option['from']];
                }
            }
            if ($options) {
                $out[$field] = ['label' => $label, 'name' => self::NAMES[$field] ?? $field, 'options' => $options];
            }
        }

        return $out;
    }

    private static function option(string $field, mixed $value, string $text): ?array
    {
        return match ($field) {
            'car' => self::car($value),
            'city' => ($place = Settlement::named((string) $value)) ? ['id' => $place['id'], 'text' => $place['title'] ?? $place['name']] : null,
            'vin' => ['value' => $v = strtoupper((string) $value), 'text' => $v],
            'color' => ['value' => $v = Colors::normalize((string) $value) ?? $text, 'text' => $v],
            'engine_volume' => ['value' => Liters::format((int) $value), 'text' => $text],
            'year', 'mileage', 'engine_power', 'value' => ['value' => (string) (int) $value, 'text' => $text],
            default => ['value' => (string) $value, 'text' => $text],
        };
    }

    private static function car(array $value): ?array
    {
        $brand = Brand::known((string) $value['brand']);
        if (! $brand) {
            return null;
        }
        $model = filled($value['model'] ?? null) ? CarModel::resolve($brand, (string) $value['model']) : null;

        return [
            'brand' => ['id' => $brand->id, 'text' => $brand->name],
            'model' => $model ? ['id' => $model->id, 'text' => $model->name] : null,
            'text' => trim($brand->name.' '.$model?->name),
        ];
    }
}
