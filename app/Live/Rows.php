<?php

namespace App\Live;

use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Живое обновление строками (событие `rows`, 16.10.2026, владелец: «постоянно всё лагает»): открытый список не
 * перечитывается морфом целиком, а просит у своего же адреса только поменявшиеся строки — заголовок `X-Live-Rows`
 * с ключами `data-detail-key`. Контроллер списка сужает выборку строк (`only`, `filter`) после чисел пилюль и итога:
 * их клиент берёт из того же ответа. Нет заголовка — страница целиком, как всегда; клиент (`live.js` patch) справится
 * и с ней — берёт строки по ключу, остальное отбрасывает.
 */
final class Rows
{
    public const HEADER = 'X-Live-Rows';

    /** Больше — дешевле перечитать страницу целиком. */
    public const MAX = 40;

    /** @return list<string>|null ключи строк из запроса; null — страница целиком */
    public static function keys(): ?array
    {
        $raw = request()->header(self::HEADER);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $keys = array_values(array_unique(array_filter(explode(',', $raw), 'ctype_digit')));

        return $keys && count($keys) <= self::MAX ? $keys : null;
    }

    /**
     * Выборка строк — только эти ключи; `$by` — столбец ключа или сужение замыканием. Страница списка — первая: строка
     * с третьей страницы плиток иначе не нашлась бы и ушла с экрана.
     */
    public static function only(Builder $q, string|Closure $by): Builder
    {
        $keys = self::keys();
        if ($keys === null) {
            return $q;
        }
        request()->query->remove('page');
        $by instanceof Closure ? $by($q, $keys) : $q->whereIn($by, $keys);

        return $q;
    }

    /**
     * Строки предложений: номер или прежний номер (`offer_number_aliases`) — при выходе в галерею и в продажу номер
     * меняется, а на экране строка ещё со старым: по нему находится новая, и клиент перечитывает список (`patch`).
     */
    public static function offers(Builder $q): Builder
    {
        return self::only($q, fn ($q, array $keys) => $q->where(fn ($w) => $w->whereIn('offers.number', $keys)
            ->orWhereIn('offers.id', DB::table('offer_number_aliases')->whereIn('number', $keys)->select('offer_id'))));
    }

    /** То же для списка, собранного в памяти. */
    public static function filter(Collection $items, Closure $key): Collection
    {
        $keys = self::keys();

        return $keys === null ? $items : $items->filter(fn ($item) => in_array((string) $key($item), $keys, true))->values();
    }
}
