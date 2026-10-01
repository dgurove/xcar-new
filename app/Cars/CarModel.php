<?php

namespace App\Cars;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['brand_id', 'slug', 'name', 'name_ru', 'class', 'year_from', 'year_to'])]
class CarModel extends Model
{
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Найти модель марки, иначе завести. Перед тем как завести — словарь и справочник (`Names::knownModel`): «Ср-В»
     * у Honda — это CR-V, «Акркана» у Renault — Arkana; новая запись — уже под правильным именем.
     */
    public static function resolve(Brand $brand, string $name): self
    {
        $name = trim($name);
        $find = fn (string $n) => $brand->models()->where('slug', Brand::slugFor($n))->orWhere(fn ($q) => $q->where('brand_id', $brand->id)->whereRaw('lower(name) = ?', [mb_strtolower($n)]))->first();
        if ($model = $find($name)) {
            return $model;
        }
        $known = Names::knownModel($brand, $name);

        return ($known ? $find($known) : null) ?? $brand->models()->create(['slug' => Brand::slugFor($known ?? $name), 'name' => $known ?? $name]);
    }
}
