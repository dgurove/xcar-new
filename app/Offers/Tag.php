<?php

namespace App\Offers;

use App\Support\FastAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Метка на оффере: название и цвет. Сами метки лежат в offers.tags списком названий. */
#[Fillable(['name', 'color', 'sort'])]
class Tag extends Model
{
    use FastAttributes;

    public const COLORS = ['lime' => 'Лайм', 'orange' => 'Оранжевый', 'red' => 'Красный', 'blue' => 'Синий', 'grey' => 'Серый'];

    /** Цвет метки: фон и текст в светлой теме, фон и текст в тёмной. */
    public const PALETTE = [
        'lime' => ['#f0f7d8', '#669709', '#1a2605', '#a6cf3a'],
        'orange' => ['#fff3e0', '#c26a00', '#2a1a00', '#ffb15c'],
        'red' => ['#ffe8ec', '#d10030', '#33000a', '#ff6b8a'],
        'blue' => ['#e3ecfb', '#2456b3', '#101c33', '#8ab4ff'],
        'grey' => ['#ececec', '#666666', '#242424', '#9a9a9a'],
        'amber' => ['#fef3c7', '#92400e', '#3f2606', '#fcd34d'],
    ];

    /** Вендоры, чьим предложениям метка ставится сама (11.10.2026, `VendorTags`). */
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'tag_vendor');
    }

    /** CSS-переменные цвета для style="" у .tag и .choice-tag. */
    public static function style(?string $color): string
    {
        $c = self::PALETTE[$color] ?? self::PALETTE['grey'];

        return sprintf('--tag-bg:%s;--tag-text:%s;--tag-bg-d:%s;--tag-text-d:%s', ...$c);
    }

    /** Цвет метки у предложения: справочник, иначе свой цвет разовой метки, иначе серый. */
    public static function colorOf(string $name, ?array $own = null): string
    {
        $colors = once(fn () => self::query()->pluck('color', 'name')->all());

        return $colors[$name] ?? $own[$name] ?? 'grey';
    }
}
