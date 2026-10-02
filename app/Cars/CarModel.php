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
     * Модель марки по любому написанию — справочник, потом словарь (`Names::knownModel`): «Ср-В» у Honda — это CR-V,
     * «Акркана» у Renault — Arkana. Нет — null: искать модель везде так, а заводить — только `resolve`.
     */
    public static function known(Brand $brand, string $name): ?self
    {
        $name = trim($name);
        $find = fn (string $n) => $brand->models()->where('slug', Brand::slugFor($n))->orWhere(fn ($q) => $q->where('brand_id', $brand->id)->whereRaw('lower(name) = ?', [mb_strtolower($n)]))->first();
        $known = Names::knownModel($brand, $name);

        return $find($name) ?? ($known ? $find($known) : null);
    }

    /** Найти модель марки (`known`), иначе завести — под именем из словаря, если он её знает. */
    public static function resolve(Brand $brand, string $name): self
    {
        $name = trim($name);
        $known = Names::knownModel($brand, $name);

        return self::known($brand, $name) ?? $brand->models()->create(['slug' => Brand::slugFor($known ?? $name), 'name' => $known ?? $name]);
    }
}
