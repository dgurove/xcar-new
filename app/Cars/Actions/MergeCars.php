<?php

namespace App\Cars\Actions;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Names;
use Illuminate\Support\Facades\DB;

/**
 * Слить дубли справочника машин. Марка: «Lada», заведённая вручную, — это запись справочника vaz «Lada (ВАЗ)»
 * (`Names::ALT`): машины, предложения, закупки и память VIN переходят на запись справочника, модели — к её
 * моделям (тот же slug) или переезжают, дубль удаляется. Модель: вписанная по-русски («Ср-В», «Акркана»), которую
 * справочник узнаёт как другую модель той же марки (`Names::knownModel`), сливается в неё.
 */
final class MergeCars
{
    /** Таблицы со ссылками на марку и модель. */
    private const TABLES = ['park_vehicles', 'offers', 'purchase_cars', 'vin_facts'];

    /** Пары марок «дубль → запись справочника», у которых в базе есть обе. @return list<array{Brand, Brand}> */
    public function brandPairs(): array
    {
        $pairs = [];
        foreach (Names::aliases() as $slug => $target) {
            $from = Brand::where('slug', $slug)->first();
            $into = Brand::where('slug', $target)->first();
            if ($from && $into && $from->id !== $into->id) {
                $pairs[] = [$from, $into];
            }
        }

        return $pairs;
    }

    /**
     * Модели, вписанные людьми в карточку ТС по-русски («Ср-В»), которые справочник узнаёт как другую, уже
     * заведённую модель той же марки (CR-V). Справочные русские имена («Волга», «C-Класс») не трогаются: на них не
     * ссылается ни одна ТС парковки.
     *
     * @return list<array{CarModel, CarModel}>
     */
    public function modelPairs(): array
    {
        $pairs = [];
        $typed = DB::table('park_vehicles')->whereNotNull('model_id')->distinct()->pluck('model_id');
        foreach (CarModel::with('brand')->whereIn('id', $typed)->get() as $model) {
            if (! $model->brand || ! preg_match('/\p{Cyrillic}/u', $model->name)) {
                continue;
            }
            $known = Names::knownModel($model->brand, $model->name, $model->id);
            $into = $known ? $model->brand->models()->whereRaw('lower(name) = ?', [mb_strtolower($known)])->where('id', '!=', $model->id)->first() : null;
            if ($into) {
                $pairs[] = [$model, $into];
            }
        }

        return $pairs;
    }

    /** Сколько строк ссылается на марку или модель — для отчёта. */
    public function uses(string $column, int $id): int
    {
        return array_sum(array_map(fn ($t) => DB::table($t)->where($column, $id)->count(), self::TABLES));
    }

    public function brand(Brand $from, Brand $into): void
    {
        DB::transaction(function () use ($from, $into) {
            foreach (CarModel::where('brand_id', $from->id)->get() as $model) {
                $twin = CarModel::where('brand_id', $into->id)->where('slug', $model->slug)->first();
                $twin ? $this->model($model, $twin) : $model->update(['brand_id' => $into->id]);
            }
            foreach (self::TABLES as $table) {
                DB::table($table)->where('brand_id', $from->id)->update(['brand_id' => $into->id]);
            }
            $from->delete();
        });
    }

    public function model(CarModel $from, CarModel $into): void
    {
        DB::transaction(function () use ($from, $into) {
            foreach (self::TABLES as $table) {
                DB::table($table)->where('model_id', $from->id)->update(['model_id' => $into->id, 'brand_id' => $into->brand_id]);
            }
            $from->delete();
        });
    }
}
