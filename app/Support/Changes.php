<?php

namespace App\Support;

use App\Offers\AudienceRules;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Что меняет правка — «было → стало» для истории (14.10.2026, владелец: после файла Carcade нельзя было сказать, какая
 * закупочная была и какая стала, — лента знала только имена полей). Зовут двери правки до записи: `UpdateOffer`,
 * `UpdateVehicle`, `UpdateDealCost`. Значения сырые (число, строка, значение перечисления, дата), подписи — при показе
 * (`FieldLabels::value`). Длинный текст в историю не пишется — только имя поля; производное (ключ номера, город ТС) —
 * не правка.
 */
final class Changes
{
    /** Длинный текст: в ленте — началом нового текста в кавычках («описание «Мост битый, …»»), у остальных — только имя. */
    public const TEXT = ['description', 'notes', 'damage_note', 'storage_rate_note'];

    /** Пересказ длинного текста — столько знаков нового (07.11.2026, владелец: «что тогда установили — не написано»). */
    private const EXCERPT = 60;

    /**
     * Производное от других полей и служебное. Цвета меток — оформление: «цвета меток» без значения в ленте ничего не
     * говорило (07.11.2026).
     */
    private const SKIP = ['claim_ref_key', 'ref_key', 'plate_key', 'vehicle_category', 'city_id', 'tab_at', 'tab_by', 'updated_at', 'created_at', 'tag_colors'];

    /**
     * Грязные поля моделей (одна правка может писать и предложение, и машину) — до `save()`.
     *
     * @return array{fields: list<string>, changes: array<string, array{0: mixed, 1: mixed}>}
     */
    public static function of(Model ...$models): array
    {
        $fields = [];
        $changes = [];
        foreach ($models as $model) {
            foreach (array_keys($model->getDirty()) as $key) {
                if (in_array($key, self::SKIP, true) || in_array($key, $fields, true)) {
                    continue;
                }
                $fields[] = $key;
                $was = $model->exists ? $model->getOriginal($key) : null;
                $now = $model->getAttribute($key);
                $changes[$key] = match (true) {
                    // «Кому показывать» — словами шторки: «Роман Корабельников сразу, остальные через 5 дней» (07.11.2026).
                    $key === 'audience_rules' => [$model->exists ? AudienceRules::summary((array) $was) : null, AudienceRules::summary((array) $now)],
                    in_array($key, self::TEXT, true) => [null, filled($now) ? '«'.Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $now)), self::EXCERPT).'»' : null],
                    default => [self::raw($was), self::raw($now)],
                };
            }
        }

        // Текст — в конце строки: суммы и названия читаются первыми.
        usort($fields, fn ($a, $b) => in_array($a, self::TEXT, true) <=> in_array($b, self::TEXT, true));

        return ['fields' => $fields, 'changes' => $changes];
    }

    /**
     * Правка дорожки машины (`Cars\OnCar`): её поля и поля машины — именами дорожки (у парковки номер убытка — `ref`,
     * страхователь — `contact_name`).
     *
     * @return array{fields: list<string>, changes: array<string, array{0: mixed, 1: mixed}>}
     */
    public static function ofLane(Model $lane): array
    {
        $names = array_flip($lane::carAliases());
        $own = self::of($lane);
        $car = self::of($lane->carRecord());
        $rename = fn (string $f) => $names[$f] ?? $f;

        // Производное и под именем дорожки (тип ТС у продажи считает машина по кузову и марке) — не правка.
        $fields = array_values(array_diff(array_unique([...$own['fields'], ...array_map($rename, $car['fields'])]), self::SKIP));
        usort($fields, fn ($a, $b) => in_array($a, self::TEXT, true) <=> in_array($b, self::TEXT, true));

        return ['fields' => $fields, 'changes' => array_diff_key($own['changes'] + collect($car['changes'])->mapWithKeys(fn ($v, $f) => [$rename($f) => $v])->all(), array_flip(self::SKIP))];
    }

    /**
     * Строка ленты: «закупочная цена 183 840 → 211 200 ₽, цена продажи 250 000 ₽, описание». Пустое было — только новое,
     * стёрли — «→ нет». Записи до 14.10.2026 без значений — одни имена полей.
     *
     * @param  list<string>  $fields
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    public static function text(array $fields, array $changes = []): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $label = FieldLabels::label($field);
            if (! array_key_exists($field, $changes)) {
                $parts[] = $label;

                continue;
            }
            [$was, $now] = $changes[$field];
            $parts[] = $label.' '.self::pair($field, $was, $now);
        }

        return implode(', ', $parts);
    }

    /** «183 840 → 211 200 ₽»: у денег знак рубля один раз, в конце. */
    public static function pair(string $field, mixed $was, mixed $now): string
    {
        $empty = fn ($v) => $v === null || $v === '' || $v === [];
        // «Кому показывать» — фраза с запятыми: в кавычках, чтобы не сливалась с соседними полями.
        if ($field === 'audience_rules') {
            return ($empty($was) ? '' : '«'.$was.'» → ').'«'.($now ?: 'всем сразу').'»';
        }
        if ($empty($was)) {
            return FieldLabels::value($field, $now) ?? 'нет';
        }
        if ($empty($now)) {
            return (FieldLabels::value($field, $was) ?? '').' → нет';
        }
        if (FieldLabels::isMoney($field)) {
            return Money::nums($was).' → '.Money::rub($now);
        }

        return FieldLabels::value($field, $was).' → '.FieldLabels::value($field, $now);
    }

    private static function raw(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i'),
            is_object($value) => (string) $value,
            default => $value,
        };
    }
}
