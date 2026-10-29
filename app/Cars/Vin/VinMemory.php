<?php

namespace App\Cars\Vin;

use App\Cars\CarModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Память декодера: что записано у машин с тем же началом VIN. Спрашивает сами машины (`cars`, индекс по
 * `substr(vin, 1, 11)`): своей копии полей по дорожкам (`vin_facts`) с 29.10.2026 нет.
 *
 * Первые восемь знаков (WMI и позиции 4–8) — это завод и модельный код;
 * дальше знак года и завода, они разводят поколения (Geely Atlas Pro →
 * Belgee X70). Берём записи, совпавшие глубже всех, и подставляем поле
 * только когда все они согласны: разошлись — модели не знаем, но
 * варианты называем.
 */
final class VinMemory
{
    public const MIN = 8;

    /** Поля, которые память берёт у машины. */
    public const FIELDS = ['brand_id', 'model_id', 'year', 'transmission', 'drive', 'fuel', 'body', 'engine_volume', 'engine_power'];

    /** Машины с маркой и полным VIN, чьё начало (первые 11 знаков) совпало с `$prefix`; `prefix` — эти 11 знаков. */
    public static function known(string $prefix): Collection
    {
        return DB::table('cars')->whereNotNull('brand_id')->where('is_demo', false)->whereRaw('length(vin) = 17')
            ->whereRaw('substr(vin, 1, 11) like ?', [addcslashes($prefix, '%_').'%'])
            ->selectRaw('substr(vin, 1, 11) as prefix, '.implode(', ', self::FIELDS))->get();
    }

    /**
     * @return array{values: array<string, mixed>, model_candidates: list<string>, count: int, depth: int}
     */
    public function recall(string $vin): array
    {
        $empty = ['values' => [], 'model_candidates' => [], 'count' => 0, 'depth' => 0];
        if (! VinDecoder::looksValid($vin)) {
            return $empty;
        }

        $facts = self::known(substr($vin, 0, self::MIN));
        if ($facts->isEmpty()) {
            return $empty;
        }

        $depth = 0;
        foreach ($facts as $fact) {
            $depth = max($depth, $this->common($vin, $fact->prefix));
        }
        $facts = $facts->filter(fn (object $f) => $this->common($vin, $f->prefix) === $depth);

        $values = [];
        foreach (array_diff(self::FIELDS, ['year']) as $field) {
            $seen = $facts->pluck($field)->filter(fn ($v) => $v !== null && $v !== '')->unique();
            if ($seen->count() === 1) {
                $values[$field] = $seen->first();
            }
        }
        // Модель без марки не бывает: разошлись марки — модель тоже под вопросом.
        if (! isset($values['brand_id'])) {
            unset($values['model_id']);
        }

        $candidates = [];
        if (! isset($values['model_id'])) {
            $ids = $facts->pluck('model_id')->filter()->unique();
            if ($ids->count() > 1) {
                $candidates = CarModel::with('brand')->whereIn('id', $ids)->get()
                    ->map(fn (CarModel $m) => trim(($m->brand?->name ?? '').' '.$m->name))->sort()->values()->all();
            }
        }

        return ['values' => $values, 'model_candidates' => $candidates, 'count' => $facts->count(), 'depth' => $depth];
    }

    /** Порядок сравнения: модельный код, знак года, завод, и только потом 9-й знак. */
    private const ORDER = [0, 1, 2, 3, 4, 5, 6, 7, 9, 10, 8];

    /**
     * Сколько знаков совпало. Девятый знак у китайских и американских VIN —
     * контрольный, он разный у одинаковых машин, поэтому сравнивается
     * последним: сначала код модели, потом год и завод (они разводят
     * поколения и переименования — Geely Atlas Pro стал Belgee X70).
     */
    private function common(string $vin, string $prefix): int
    {
        $n = 0;
        foreach (self::ORDER as $i) {
            if (($vin[$i] ?? null) === null || ($prefix[$i] ?? null) !== $vin[$i]) {
                break;
            }
            $n++;
        }

        return $n;
    }
}
