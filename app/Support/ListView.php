<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * Вид списка: `?vid=shelf` (ленты), `?vid=grid`, `?vid=list`, `?vid=table` и `?vid=wide` (подробная таблица) — явный выбор,
 * он живёт в адресе; без выбора в CRM и на парковке — таблица, на сайте — ленты, где у списка есть пачки, иначе плитки (pick).
 * «Ленты» и «Плитки» (15.10.2026, начальник): ленты — пачки плиток с подзаголовком лентой вбок (`x-ui.shelf`), весь список
 * одной страницей; плитки — та же карточка обычной сеткой, без подзаголовков и со страницами.
 * Таблица — своя разметка (x-ui.table), не .cards.
 * Сколько на странице — `?per=` из набора вида (perPage): плиткам 24 / 48 / 96 (три колонки по
 * восемь рядов), строкам 30 / 60 / 120 (та же длина прокрутки), плотным спискам без вида
 * 50 / 100 / 200; таблица — порциями по сотне при листании (`Portion`, 31.10.2026; до того — целиком на одной странице).
 * Чужое число — первое из набора.
 */
final class ListView
{
    public const PARAM = 'vid';

    public const PER = 'per';

    public const PER_GRID = [24, 48, 96];

    public const PER_LIST = [30, 60, 120];

    public const PER_ROWS = [50, 100, 200];

    public const LIST = 'list';

    public const GRID = 'grid';

    /** Ленты: пачки плиток лентой вбок. Есть только у списков с пачками — страница передаёт переключателю `SHELVED`. */
    public const SHELF = 'shelf';

    public const TABLE = 'table';

    /** Подробная таблица: на телефоне все столбцы, что видны на ПК, с прокруткой вбок; на ПК — как TABLE. */
    public const WIDE = 'wide';

    public const ALL = [self::GRID, self::LIST, self::TABLE, self::WIDE];

    /** Виды списка с пачками: первыми — ленты. */
    public const SHELVED = [self::SHELF, self::GRID, self::LIST, self::TABLE, self::WIDE];

    /** Таблица любого вида — краткая или подробная: одна разметка, одна страница, одно карточка. */
    public static function isTable(?string $view): bool
    {
        return $view === self::TABLE || $view === self::WIDE;
    }

    /** Плитки любого вида — сеткой или лентами: одна карточка, одни кадры и «по сколько». */
    public static function isTiles(?string $view): bool
    {
        return $view === self::GRID || $view === self::SHELF;
    }

    /** shelves — у списка есть пачки; без них «ленты» из старой ссылки — просто плитки. */
    public static function fromRequest(Request $request, bool $shelves = false): ?string
    {
        $vid = $request->query(self::PARAM);
        if ($vid === self::SHELF) {
            return $shelves ? self::SHELF : self::GRID;
        }

        return in_array($vid, self::ALL, true) ? $vid : null;
    }

    /** Набор «по сколько» для вида; таблице — пустой: она порциями (`Portion`). */
    public static function perSizes(?string $view): array
    {
        return match ($view) {
            self::TABLE, self::WIDE => [],
            self::LIST => self::PER_LIST,
            default => self::PER_GRID,
        };
    }

    public static function perPage(Request $request, array $sizes): int
    {
        $per = (int) $request->query(self::PER);

        return in_array($per, $sizes, true) ? $per : $sizes[0];
    }

    /**
     * Постраничка карточного списка: сколько на странице зависит от вида, а вид без выбора —
     * от длины (pick), поэтому счёт идёт до paginate. Шаблон берёт вид тем же pick по total().
     * Таблица — порциями (`Portion`): первая сотня, дальше при листании.
     * whole — весь список одной страницей: плитки пачками лентами (x-ui.shelf) — пачку страница не рвёт; таблица, у которой
     * порция соврёт, — сортировка в памяти (честна только на всём списке) или «Выбрать все» галками (выбрало бы одну порцию).
     */
    public static function paginate(Request $request, Builder|Relation $q, ?int $count = null, bool $whole = false): LengthAwarePaginator
    {
        // Число уже посчитано — второй раз его не считает и сама постраничка (`total:`).
        $count ??= $q->count();
        $view = self::pick($request, $count);
        if (self::isTable($view) && ! $whole) {
            return Portion::of($request, $q, $count);
        }
        $per = self::isTable($view) || $whole ? max($count, 1) : self::perPage($request, self::perSizes($view));

        return $q->paginate($per, total: $count)->withQueryString();
    }

    /**
     * Вид без выбора: в CRM и на парковке всегда таблица (и на телефоне, и на ПК; парковка — владелец 09.10.2026), на сайте —
     * плитки при любой длине и ширине (владелец 11.10.2026: «вид плитки по умолчанию на всех страницах xcar», прежнее
     * «длиннее 20 — таблицей» и «на телефоне строками» отменены).
     */
    public static function pick(Request $request, int $count, bool $shelves = false): ?string
    {
        return self::fromRequest($request, $shelves) ?? match (true) {
            Surface::current() !== Surface::Site => self::TABLE,
            // Ленты — только по выбору человека (22.10.2026, владелец: «по умолчанию у всех плитки»).
            default => self::GRID,
        };
    }

    /**
     * sizes для кадра карточки: в строке кадр 4.5–6 rem, и без этого браузер
     * на телефоне берёт оригинал 1600 px под каждую из двадцати карточек.
     */
    public static function sizes(?string $view): string
    {
        return match ($view) {
            self::LIST, self::TABLE, self::WIDE => '(min-width: 640px) 6rem, 4.5rem',
            self::GRID => '(min-width: 1024px) 320px, (min-width: 640px) 45vw, 100vw',
            // Плитка ленты — 72 % ширины телефона, от 640 — 18rem (`.cards-shelf`).
            self::SHELF => '(min-width: 640px) 18rem, 72vw',
            default => '(min-width: 1024px) 320px, (min-width: 640px) 45vw, 4.5rem',
        };
    }

    public static function containerClass(?string $view): string
    {
        return match ($view) {
            self::LIST => 'cards cards--list',
            self::GRID, self::SHELF => 'cards cards--grid',
            default => 'cards',
        };
    }
}
