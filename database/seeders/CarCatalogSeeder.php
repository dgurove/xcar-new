<?php

namespace Database\Seeders;

use App\Cars\Brand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Марки и модели из database/data/cars.csv (справочник, ~4600 строк). */
class CarCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $file = fopen(database_path('data/cars.csv'), 'r');
        fgetcsv($file, escape: '\\');
        $brands = [];
        $models = [];
        $now = now();

        while (($row = fgetcsv($file, escape: '\\')) !== false) {
            [$brandId, $brand, $brandRu, $popular, $country, , , $modelId, $model, $modelRu, $class, $from, $to] = array_pad($row, 13, null);
            if (! $brand || ! $model) {
                continue;
            }
            $slug = Str::slug($brandId) ?: Str::slug($brand);
            $brands[$slug] ??= ['slug' => $slug, 'name' => $brand, 'name_ru' => $brandRu ?: null, 'country' => $country ?: null, 'is_popular' => (bool) $popular, 'created_at' => $now, 'updated_at' => $now];
            $models[] = ['brand' => $slug, 'slug' => Str::slug(Str::after($modelId, $brandId.'_')) ?: Str::slug($model), 'name' => $model, 'name_ru' => $modelRu ?: null, 'class' => $class ?: null, 'year_from' => $from ?: null, 'year_to' => $to ?: null];
        }
        fclose($file);

        DB::transaction(function () use ($brands, $models, $now) {
            foreach (array_chunk(array_values($brands), 500) as $chunk) {
                DB::table('brands')->upsert($chunk, ['slug'], ['name', 'name_ru', 'country', 'is_popular']);
            }
            $ids = Brand::pluck('id', 'slug');
            $rows = [];
            $seen = [];
            foreach ($models as $m) {
                $key = $m['brand'].'/'.$m['slug'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = ['brand_id' => $ids[$m['brand']], 'slug' => $m['slug'], 'name' => $m['name'], 'name_ru' => $m['name_ru'], 'class' => $m['class'], 'year_from' => $m['year_from'], 'year_to' => $m['year_to'], 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('car_models')->upsert($chunk, ['brand_id', 'slug'], ['name', 'name_ru', 'class', 'year_from', 'year_to']);
            }
        });
    }
}
