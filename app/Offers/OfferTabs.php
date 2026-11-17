<?php

namespace App\Offers;

use App\Http\Admin\OfferController;
use App\Workflow\Track;
use App\Support\StaffCache;
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

    /** @var array<string, mixed> выражения по нынешней раскладке (`sql`, `sinceSql`) */
    private static array $memo = [];

    /**
     * Вкладки, по которым раскладываются машины «Всех» и сводных разделов, по порядку колеса. Закупки — не предложения,
     * галерея — окно показа черновика параллельно его вкладке (16.11.2026): машина остаётся в группе своего черновика, у строки —
     * метка «в галерее»; сводные (`OfferController::SUMMARIES`) сами раскладываются по этим. «Архив» — с 17.11.2026, для
     * «Архивов»: во «Все» архивного нет по состоянию.
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_values(array_diff(array_keys(OfferController::PRESETS),
            ['all', 'purchases', 'gallery', 'interest', ...array_keys(OfferController::SUMMARIES)]));
    }

    /** `offer_id` → ключ вкладки. @return array<int, string> */
    public static function map(): array
    {
        return StaffCache::remember(self::CACHE, 30, function () {
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

    /** Номера предложений, что раскладка кладёт в эти вкладки. @param list<string> $keys @return list<int> */
    public static function ids(array $keys): array
    {
        return array_keys(array_filter(self::map(), fn ($key) => in_array($key, $keys, true)));
    }

    public static function label(string $key): string
    {
        return OfferController::PRESETS[$key] ?? 'Прочее';
    }

    /** Ключ вкладки выражением SQL по номеру предложения (`offers.id`, у гаража — `garage_cars.offer_id`); номера — целые из своей раскладки. */
    public static function sql(string $column = 'offers.id'): string
    {
        // Выражение одно на раскладку: «Все» зовёт его по нескольку раз на запрос (выборка, порядок, группы, чипы, «Во
        // вкладке»). Octane живёт между запросами — сменилась раскладка (кэш 30 с), строится заново.
        $map = self::map();
        if ((self::$memo['map'] ?? null) !== $map) {
            self::$memo = ['map' => $map];
        }

        return self::$memo['sql:'.$column] ??= self::build($map, $column);
    }

    /** @param array<int, string> $map */
    private static function build(array $map, string $column): string
    {
        $by = [];
        foreach ($map as $id => $key) {
            $by[$key][] = (int) $id;
        }
        $cases = collect(self::keys())->filter(fn ($k) => isset($by[$k]))
            ->map(fn ($k) => "when {$column} in (".implode(',', $by[$k]).") then '{$k}'")->implode(' ');

        return $cases === '' ? "'".self::OTHER."'" : "(case {$cases} else '".self::OTHER."' end)";
    }

    /**
     * С какого момента машина в своей вкладке — выражением SQL по `offers` (17.11.2026, владелец: «сколько дней и часов она уже в
     * этой вкладке»): этап сделки — вход в блок шага продажи (вкладка сделки — блок, `DealTabs::BLOCKS`), гараж после сделки —
     * вход в этап гаража, «Есть подтверждения» — первое живое подтверждение, остальное — `tab_at` (`Offer::tabKeyOf`).
     */
    public static function sinceSql(): string
    {
        $sql = self::sql();

        return self::$memo['since'] ??= self::since($sql);
    }

    private static function since(string $sql): string
    {
        $deal = "(select coalesce(p.block_entered_at, p.entered_at) from offer_positions p where p.offer_id = offers.id and p.track = '".Track::Sale->value."' limit 1)";
        $whens = collect(array_keys(DealTabs::TABS))->map(fn ($k) => "when '{$k}' then {$deal}")->implode(' ');
        $garage = '(select coalesce(g.stage_at, g.taken_at) from garage_cars g where g.offer_id = offers.id limit 1)';
        $bids = "(select min(b.created_at) from bids b where b.offer_id = offers.id and b.state = '".BidState::Active->value."')";

        return 'coalesce((case '.$sql." {$whens} when 'garage' then {$garage} when 'bids' then {$bids} end), offers.tab_at, offers.created_at)";
    }

    /** Порядок вкладки по колесу выражением SQL: 0, 1, 2… «Прочее» — последним. */
    public static function orderSql(string $column = 'offers.id'): string
    {
        $cases = collect(self::keys())->map(fn ($k, $i) => "when '{$k}' then {$i}")->implode(' ');

        return '(case '.self::sql($column)." {$cases} else 999 end)";
    }

    public static function forget(): void
    {
        StaffCache::bump(StaffCache::TABS);
        self::$memo = [];
    }
}
