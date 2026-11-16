<?php

namespace App\Offers;

use App\Http\Admin\OfferController;
use Illuminate\Support\Facades\Cache;

/**
 * В какой вкладке «Предложений» машина — для «Всех» (16.11.2026, владелец: «группировку как по вкладкам», «в состоянии писать,
 * в какой они вкладке», «фильтр — по этому состоянию»). Правило — условия самих вкладок (`OfferController::scopeFor`, вид
 * админа) по порядку колеса: первая подошедшая решает, сделки — по `DealTabs`. Не попала никуда — «Прочее» (`other`).
 * Раскладка — раз на 30 с (`Cache`, сброс — `Nav::forgetStaffCounts`); `sql()` — она же выражением для порядка, чисел групп и
 * чипа «Вкладка».
 */
final class OfferTabs
{
    public const OTHER = 'other';

    private const CACHE = 'offer-tabs';

    /** Вкладки, по которым раскладываются машины «Всех», по порядку колеса. @return list<string> */
    public static function keys(): array
    {
        return array_values(array_diff(array_keys(OfferController::PRESETS), ['all', 'archive']));
    }

    /** `offer_id` → ключ вкладки. @return array<int, string> */
    public static function map(): array
    {
        return Cache::remember(self::CACHE, 30, function () {
            $out = [];
            foreach (self::keys() as $key) {
                $ids = OfferController::scopeFor($key, Offer::query()->notEmptyDraft(), true)->pluck('offers.id');
                foreach ($ids as $id) {
                    $out[$id] ??= $key;
                }
            }

            return $out;
        });
    }

    public static function of(Offer $offer): string
    {
        return self::map()[$offer->id] ?? self::OTHER;
    }

    public static function label(string $key): string
    {
        return OfferController::PRESETS[$key] ?? 'Прочее';
    }

    /** Ключ вкладки выражением SQL (`offers.id`); номера — целые из своей раскладки. */
    public static function sql(): string
    {
        $by = [];
        foreach (self::map() as $id => $key) {
            $by[$key][] = (int) $id;
        }
        $cases = collect(self::keys())->filter(fn ($k) => isset($by[$k]))
            ->map(fn ($k) => "when offers.id in (".implode(',', $by[$k]).") then '{$k}'")->implode(' ');

        return $cases === '' ? "'".self::OTHER."'" : "(case {$cases} else '".self::OTHER."' end)";
    }

    /** Порядок вкладки по колесу выражением SQL: 0, 1, 2… «Прочее» — последним. */
    public static function orderSql(): string
    {
        $cases = collect(self::keys())->map(fn ($k, $i) => "when '{$k}' then {$i}")->implode(' ');

        return '(case '.self::sql()." {$cases} else 999 end)";
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }
}
