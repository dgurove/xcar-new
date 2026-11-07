<?php

namespace App\Cars\Sources;

use App\Cars\Car;
use App\Cars\Rejections;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Park\VehicleFields;
use Illuminate\Support\Facades\DB;

/**
 * Спорные поля машины (07.11.2026, владелец: «по этому полю в Мигторге так, в этом письме так, в этом документе так —
 * выберите»): по полю — значение сейчас и варианты источников (`car_values`), одинаковые по ключу склеены, у каждого —
 * откуда. Поле спорное, когда вариант расходится с тем, что вписано, или у пустого поля источники не согласны. Вариант,
 * который отклонили (или проиграл при выборе), больше не показывается ни в CRM, ни на парковке (`Rejections`, источник
 * `variant`, значение — ключ); новый — показывается. Поля — те, что дорожка пишет: у парковки нет города и характеристик,
 * у продажи — оценочной.
 */
final class Variants
{
    public const REJECT = 'variant';

    /** Колонки машины, о которых говорят источники: поменялась любая — спорные заново (`Car::saved`). */
    public const COLUMNS = ['brand_id', 'model_id', 'vin', 'year', 'color', 'mileage', 'plate', 'pts', 'sts', 'body', 'transmission', 'drive', 'fuel',
        'engine_volume', 'engine_power', 'settlement_id', 'value'];

    /**
     * @return array<string, array{field: string, label: string, current: ?array{key: string, label: string, sources: list<array>}, options: list<array{key: string, label: string, sources: list<array>}>}>
     */
    public static function for(Car $car, Offer|Vehicle|null $lane = null): array
    {
        $lane ??= $car->vehicle ?? $car->offer;
        $allowed = self::fields($lane);
        if (! $allowed) {
            return [];
        }
        $rows = DB::table('car_values')->where('car_id', $car->id)->whereIn('field', $allowed)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $rejected = Rejections::of(Rejections::car($car->id), self::REJECT);
        $legacy = array_merge_recursive(Rejections::of(Rejections::car($car->id), 'docs'), Rejections::of(Rejections::car($car->id), 'migtorg'));
        $out = [];
        foreach ($rows->groupBy('field') as $field => $group) {
            $now = Values::current($lane, $field);
            $current = $now ? ['key' => $now['key'], 'label' => $now['label'], 'sources' => []] : null;
            $options = [];
            foreach ($group as $row) {
                $source = ['kind' => $row->source, 'id' => $row->source_id, 'title' => $row->title];
                if ($current && Values::same($field, $row->key, $current['key'])) {
                    $current['sources'][] = $source;

                    continue;
                }
                if (in_array($row->key, $rejected[$field] ?? [], true) || self::legacy($legacy, $field, $row->label)) {
                    continue;
                }
                $options[$row->key] ??= ['key' => $row->key, 'label' => $row->label, 'write' => json_decode($row->write, true), 'sources' => []];
                $options[$row->key]['sources'][] = $source;
            }
            // Пустое поле с одним согласным вариантом — не спор: его вписывает `Pick::fillEmpty`.
            $disputed = $current ? (bool) $options : count($options) > 1;
            if ($disputed) {
                $out[$field] = ['field' => $field, 'label' => Values::title($field), 'current' => $current, 'options' => array_values($options)];
            }
        }

        // Порядок — как поля в карточке.
        return array_filter(array_replace(array_fill_keys(array_keys(Values::FIELDS), null), $out));
    }

    /**
     * Спорные поля дорожки, которые видит вошедший (правит её: продажу — дверь `offer.edit`, ТС — `canManagePark`),
     * только из `$fields` — для блока карточки. @param list<string> $fields
     */
    public static function visible(Offer|Vehicle|null $lane, array $fields): array
    {
        $user = auth()->user();
        if (! $lane?->exists || ! $user || ! ($lane instanceof Offer ? $lane->isEditableBy($user) : $user->canManagePark())) {
            return [];
        }

        return array_intersect_key(self::for($lane->carRecord(), $lane), array_flip($fields));
    }

    /**
     * Отклонённое до 07.11.2026 — текстом по старым именам полей («Мигторг отклонён: X6 XDRIVE30D», «Отклонить» у документа
     * парковки): тот же текст в подписи варианта — вариант не показывается.
     */
    private static function legacy(array $legacy, string $field, string $label): bool
    {
        $names = ['car' => ['car', 'model', 'model_id', 'brand_id'], 'city' => ['city', 'settlement_id']][$field] ?? [$field];
        $norm = fn (string $t) => mb_strtolower((string) preg_replace('/[\s\-]+/u', '', $t));
        foreach ($names as $name) {
            foreach ($legacy[$name] ?? [] as $text) {
                $t = $norm((string) $text);
                if ($t !== '' && (str_ends_with($norm($label), $t) || $norm($label) === $t)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Поля, которые дорожка принимает из источника. @return list<string> */
    public static function fields(Offer|Vehicle|null $lane): array
    {
        if (! $lane) {
            return [];
        }
        $columns = $lane instanceof Vehicle ? array_keys(VehicleFields::rules(identity: false)) : ['brand_id', 'model_id', 'vin', 'year', 'color', 'mileage', 'plate', 'pts', 'sts',
            'body', 'transmission', 'drive', 'fuel', 'engine_volume', 'engine_power', 'settlement_id'];

        return array_keys(array_filter(Values::FIELDS, fn ($f) => ! array_diff($f[1], $columns)));
    }

    /** Сколько спорных — в `cars.value_conflicts` (метка в строках без разбора). */
    public static function recount(Car $car): int
    {
        $n = count(self::for($car));
        if ((int) $car->value_conflicts !== $n) {
            DB::table('cars')->where('id', $car->id)->update(['value_conflicts' => $n]);
            $car->setRawAttributes(['value_conflicts' => $n] + $car->getAttributes(), true);
        }

        return $n;
    }
}
