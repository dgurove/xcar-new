<?php

namespace App\Cars;

use App\Offers\Offer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['slug', 'name', 'name_ru', 'country', 'is_popular'])]
class Brand extends Model
{
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function models(): HasMany
    {
        return $this->hasMany(CarModel::class)->orderBy('name');
    }

    public static function slugFor(string $name): string
    {
        return Str::slug($name) ?: mb_strtolower($name);
    }

    /** Найти по любому написанию, иначе завести. */
    public static function resolve(string $name): self
    {
        $name = trim($name);
        $slug = self::slugFor($name);

        return self::query()->where('slug', $slug)->orWhereRaw('lower(name) = ?', [mb_strtolower($name)])->orWhereRaw('lower(name_ru) = ?', [mb_strtolower($name)])->first()
            ?? self::create(['slug' => $slug, 'name' => $name]);
    }
}
