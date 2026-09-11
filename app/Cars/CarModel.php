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

    public static function resolve(Brand $brand, string $name): self
    {
        $name = trim($name);
        $slug = Brand::slugFor($name);

        return $brand->models()->where('slug', $slug)->orWhere(fn ($q) => $q->where('brand_id', $brand->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)]))->first()
            ?? $brand->models()->create(['slug' => $slug, 'name' => $name]);
    }
}
