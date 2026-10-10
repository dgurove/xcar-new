<?php

namespace App\Cars;

use App\Offers\Offer;
use App\Search\Term;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['slug', 'name', 'name_ru', 'country', 'is_popular'])]
class Brand extends Model
{
    /** Марку завели, переименовали или удалили — словарь `Names` перечитает справочник. */
    protected static function booted(): void
    {
        static::saved(fn () => Names::forget());
        static::deleted(fn () => Names::forget());
    }

    /** Марка по имени или русскому имени: кусок слова (поиск) или начало (`prefix`, подсказка поля «Марка»). */
    public function scopeSearch(Builder $q, string $word, bool $prefix = false): Builder
    {
        $like = Term::like(mb_strtolower(trim($word)), $prefix);

        return $q->where(fn ($b) => $b->whereRaw('lower(brands.name) like ?', [$like])->orWhereRaw('lower(brands.name_ru) like ?', [$like]));
    }

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

    /**
     * Найти по любому написанию; нет — null (разбор письма новых марок не заводит). Сначала словарь `Names`: он знает,
     * что «Lada» — это запись справочника vaz «Lada (ВАЗ)», и не даёт завести её второй раз.
     */
    public static function known(string $name): ?self
    {
        $name = trim($name);
        $found = Names::brand($name)
            ?? self::query()->where('slug', self::slugFor($name))->orWhereRaw('lower(name) = ?', [mb_strtolower($name)])->orWhereRaw('lower(name_ru) = ?', [mb_strtolower($name)])->first();
        // «ВАЗ (Lada)» Мигторга — та же запись, что наша «Lada (ВАЗ)»: каждое из двух написаний по отдельности.
        if (! $found && preg_match('/^(.+?)\s*\((.+)\)$/u', $name, $m)) {
            $found = self::known($m[1]) ?? self::known($m[2]);
        }

        return $found;
    }

    /** Найти по любому написанию, иначе завести. */
    public static function resolve(string $name): self
    {
        $name = trim($name);

        return self::known($name) ?? self::create(['slug' => self::slugFor($name), 'name' => $name]);
    }
}
