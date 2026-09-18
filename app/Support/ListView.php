<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Вид списка: без выбора — строки на телефоне и плитки от 640 (решает CSS),
 * `?vid=list`, `?vid=grid` и `?vid=table` — явный выбор, он живёт в адресе;
 * без выбора список длиннее 20 строк открывается таблицей (pick).
 * Таблица — своя разметка (x-ui.table), не .cards.
 * Сколько на странице — `?per=` из набора вида (perPage): карточкам 24 / 48 / 96 (24 делится
 * на 2, 3 и 4 колонки), таблице и плотным спискам 50 / 100 / 200; чужое число — первое из набора.
 */
final class ListView
{
    public const PARAM = 'vid';

    public const PER = 'per';

    public const PER_CARDS = [24, 48, 96];

    public const PER_ROWS = [50, 100, 200];

    public const LIST = 'list';

    public const GRID = 'grid';

    public const TABLE = 'table';

    public const ALL = [self::GRID, self::LIST, self::TABLE];

    public static function fromRequest(Request $request): ?string
    {
        $vid = $request->query(self::PARAM);

        return in_array($vid, self::ALL, true) ? $vid : null;
    }

    /** Набор «по сколько» для вида: таблице — строки, плиткам и строкам-карточкам — карточки. */
    public static function perSizes(?string $view): array
    {
        return $view === self::TABLE ? self::PER_ROWS : self::PER_CARDS;
    }

    public static function perPage(Request $request, array $sizes): int
    {
        $per = (int) $request->query(self::PER);

        return in_array($per, $sizes, true) ? $per : $sizes[0];
    }

    /**
     * Постраничка карточного списка: сколько на странице зависит от вида, а вид без выбора —
     * от длины (pick), поэтому счёт идёт до paginate. Шаблон берёт вид тем же pick по total().
     */
    public static function paginate(Request $request, Builder $q): LengthAwarePaginator
    {
        $view = self::pick($request, $q->count());

        return $q->paginate(self::perPage($request, self::perSizes($view)))->withQueryString();
    }

    /** Вид без выбора: длинный список (больше 20) сразу таблицей, короткий — решает CSS. */
    public static function pick(Request $request, int $count): ?string
    {
        return self::fromRequest($request) ?? ($count > 20 ? self::TABLE : null);
    }

    /**
     * sizes для кадра карточки: в строке кадр 4.5–6 rem, и без этого браузер
     * на телефоне берёт оригинал 1600 px под каждую из двадцати карточек.
     */
    public static function sizes(?string $view): string
    {
        return match ($view) {
            self::LIST, self::TABLE => '(min-width: 640px) 6rem, 4.5rem',
            self::GRID => '(min-width: 1024px) 320px, (min-width: 640px) 45vw, 100vw',
            default => '(min-width: 1024px) 320px, (min-width: 640px) 45vw, 4.5rem',
        };
    }

    public static function containerClass(?string $view): string
    {
        return match ($view) {
            self::LIST => 'cards cards--list',
            self::GRID => 'cards cards--grid',
            default => 'cards',
        };
    }
}
