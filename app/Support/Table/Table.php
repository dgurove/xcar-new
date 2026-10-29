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
     * @param  list<Column|null>  $columns  `null` — столбца нет (по условию)
     * @param  array<string, mixed>  $context  что строке нужно знать о таблице сверх столбцов (поиск — список смешанный)
     */
    public function __construct(array $columns, public readonly array $context = [])
    {
        $keyed = [];
        foreach (array_filter($columns) as $c) {
            $keyed[$c->key] = $c;
        }
        $this->columns = $keyed;
    }

    /** Столбцы по условию: `null` в списке — столбца нет. */
    public static function of(?Column ...$columns): self
    {
        return new self($columns);
    }

    public function column(string $key): ?Column
    {
        return $this->columns[$key] ?? null;
    }

    public function context(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    /** Сколько rem у столбцов кроме названия (`x-ui.table :rest`): название забирает остальное. */
    public function rest(): float
    {
        return array_sum(array_map(fn (Column $c) => $c->grow ? 0 : $c->rem, $this->columns));
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
            $c->grow && $c->cap && $fill => 'minmax(12rem, calc(var(--title-ch, 24) * .5rem + 3.5rem))',
            $c->grow => 'minmax(12rem, 1fr)',
            $c->fill && ! $open => 'minmax('.$c->rem.'rem, 1fr)',
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
