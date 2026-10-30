<?php

namespace App\Support\Table;

/**
 * Таблица списка — столбцы одним местом (27.10.2026, владелец: «единые модули, а не костыли»). Описание строит контроллер
 * списка одним методом (`DealController::table`), его читают шапка (`x-table.head`), каждая строка (`has`, `cls`) и
 * заголовки групп (`x-table.group`, `colspan` = `span`). Строку рисует только сам список — и на странице, и в ответе
 * живого перечитывания (`X-Live-Rows`); карточка строк не рисует.
 */
final class Table
{
    /** @var array<string, Column> */
    public readonly array $columns;

    /**
     * Порядок ролей в строке (`ColRole`): слева название, код и что с ним; справа кто, где, сколько и когда — дата самая
     * правая (владелец 30.10.2026: «даты обычно справа пишут», «номера убытка логичнее после наименований»).
     */
    public const ORDER = [ColRole::Pick, ColRole::Kind, ColRole::Title, ColRole::Code, ColRole::State, ColRole::Who, ColRole::Place, ColRole::Amount, ColRole::When];

    /**
     * Парковка («Заявки», «Наличие»): дата → дни → ставка → начислено вытекают друг из друга, парковка — самая правая
     * (владелец: «её проще всего визуально отличать, когда она справа»).
     */
    public const PARK = [ColRole::Pick, ColRole::Kind, ColRole::Title, ColRole::Code, ColRole::State, ColRole::Who, ColRole::When, ColRole::Amount, ColRole::Place];

    /**
     * Столбцы встают по роли (`ORDER` или `PARK`), внутри роли — в порядке объявления; запас ширины забирает последний
     * столбец левой группы (`fill`) — пустота между группами, а не внутри.
     *
     * @param  list<Column|null>  $columns  `null` — столбца нет (по условию)
     * @param  array<string, mixed>  $context  что строке нужно знать о таблице сверх столбцов (поиск — список смешанный)
     * @param  list<ColRole>  $order
     */
    public function __construct(array $columns, public readonly array $context = [], array $order = self::ORDER)
    {
        $rank = array_flip(array_map(fn (ColRole $r) => $r->value, $order));
        $list = array_values(array_filter($columns));
        $at = [];
        foreach ($list as $i => $c) {
            $at[spl_object_id($c)] = $i;
        }
        usort($list, fn (Column $a, Column $b) => [$rank[$a->role()->value], $at[spl_object_id($a)]] <=> [$rank[$b->role()->value], $at[spl_object_id($b)]]);
        // Запас — последнему столбцу левой группы из тех, что видны раньше всех (`from`): скрытый на узком экране его бы
        // не взял («№» сделок — с 1536).
        $fill = null;
        $seen = PHP_INT_MAX;
        foreach ($list as $c) {
            $from = $c->from === null ? 0 : self::STEPS[$c->from];
            if (! $c->role()->right() && ! in_array($c->role(), [ColRole::Title, ColRole::Pick, ColRole::Kind], true) && ! $c->phone && $from <= $seen) {
                [$fill, $seen] = [$c->key, $from];
            }
        }
        $keyed = [];
        foreach ($list as $c) {
            $keyed[$c->key] = $c->withFill($c->key === $fill);
        }
        $this->columns = $keyed;
    }

    /** Столбцы по условию: `null` в списке — столбца нет. */
    public static function of(?Column ...$columns): self
    {
        return new self($columns);
    }

    /** То же для парковки — её порядок (`PARK`). */
    public static function park(?Column ...$columns): self
    {
        return new self($columns, order: self::PARK);
    }

    public function column(string $key): ?Column
    {
        return $this->columns[$key] ?? null;
    }

    public function context(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->columns[$key]);
    }

    /** Классы ячейки столбца; столбца нет — пусто (строка его и не рисует: `@if ($table->has(...))`). */
    public function cls(string $key, string $extra = ''): string
    {
        return trim(($this->columns[$key] ?? null)?->classes().' '.$extra);
    }

    /** Ступени ширины, с которых столбец виден (`Column::$from`), — как у Tailwind. */
    private const STEPS = ['sm' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4, '2xl' => 5];

    /**
     * Столбцы сеткой на ПК (29.10.2026, «Все» CRM — 749 строк: открыть карточку строки — полсекунды): от 640 каждая строка
     * таблицы — сетка с этими столбцами, и строка за экраном не считается вовсе (`content-visibility`, `.is-grid` в app.css).
     * Строки совпадают столбцами без общей раскладки, только если ширины не зависят от содержимого, — они из описания
     * и данных: название — шириной в самое длинное (`--title-ch`) и не уже 12rem, `fill` забирает запас, остальное — по
     * самим значениям столбца (`Widths::of` — сервер считает один раз, браузер ничего не меряет), `rem` — когда их нет;
     * запас ширины сетка отдаёт сначала названию (до его полной длины), остаток — `fill`.
     * Строка `--cols-*` на каждую ступень ширины (у ступени свои видимые столбцы) и на открытую карточку (краткая таблица:
     * только столбцы телефона; от 1024 ещё без `aside`).
     *
     * @param  array<string, float>  $widths  ширины столбцов по данным, rem (`Widths::of`)
     */
    public function gridStyle(array $widths = []): string
    {
        $vars = [];
        foreach (self::STEPS as $step => $n) {
            $vars["--cols-{$step}"] = $this->tracks(fn (Column $c) => ! $c->phone && ($c->from === null || self::STEPS[$c->from] <= $n), $widths);
        }
        $vars['--cols-open'] = $this->tracks(fn (Column $c) => $c->from === null, $widths, open: true);
        $vars['--cols-open-lg'] = $this->tracks(fn (Column $c) => $c->from === null && ! $c->aside, $widths, open: true);

        return collect($vars)->map(fn ($v, $k) => "{$k}: {$v};")->implode(' ');
    }

    /**
     * @param  \Closure(Column): bool  $shown
     * @param  array<string, float>  $widths
     */
    private function tracks(\Closure $shown, array $widths, bool $open = false): string
    {
        $cols = array_values(array_filter($this->columns, $shown));
        $fill = ! $open && collect($cols)->contains(fn (Column $c) => $c->fill);

        // Каждая строка — своя сетка, поэтому ширины не зависят от содержимого (иначе строки разошлись бы столбцами); в
        // краткой таблице (открыта карточка) числа — по содержимому своей строки, название — остаток, как в списке телефона.
        return collect($cols)->map(fn (Column $c) => match (true) {
            $c->grow && $open => 'minmax(0, 1fr)',
            // Название — шириной в самое длинное (`--title-w` — по значениям, `Widths`), запас — `fill`.
            $c->grow && $fill => 'minmax(12rem, var(--title-w, calc(var(--title-ch, 24) * .5rem + 3.5rem)))',
            $c->grow => 'minmax(12rem, 1fr)',
            // Растяжка: код не переносится — не уже своих значений; состояние — по значениям, но не шире 12rem: длиннее
            // переносится второй строкой, а не отнимает место у названия.
            $c->fill && ! $open => 'minmax('.($c->role() === ColRole::State ? max($c->rem ?: 6, min($widths[$c->key] ?? 0, 12)) : ($widths[$c->key] ?? $c->rem)).'rem, 1fr)',
            $c->num && $open => 'max-content',
            // Ширина — из самих значений (и шапки); `rem` описания — только когда их нет (столбца нет в строках).
            default => ($widths[$c->key] ?? ($c->rem > 0 ? $c->rem : 6)).'rem',
        })->implode(' ');
    }

    /** Сколько столбцов — `colspan` заголовка группы. */
    public function span(): int
    {
        return count($this->columns);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->columns);
    }
}
