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
     * В каком порядке столбцы уходят, когда таблица не влезает, — первым самый неважный: позже всех видимый на узком экране
     * (`from`: 2xl → sm), дальше по роли — кто, где, тип, состояние, сумма, код, дата. Название и галочка не уходят.
     */
    private const DROP = [ColRole::Who, ColRole::Place, ColRole::Kind, ColRole::State, ColRole::Amount, ColRole::Code, ColRole::When];

    /**
     * Столбцы сеткой на ПК (29.10.2026, «Все» CRM — 749 строк: открыть карточку строки — полсекунды): от 640 каждая строка
     * таблицы — сетка с одними и теми же столбцами, и строка за экраном не считается вовсе (`content-visibility`, `.is-grid`).
     * Строки совпадают столбцами без общей раскладки, только если ширины не зависят от содержимого строки, — они из данных
     * всего списка (`Widths::of` — сервер считает один раз, браузер ничего не меряет).
     *
     * Строка на ПК всегда одна линия, и с открытой карточкой тоже (30.10.2026, владелец: «пихаем всё в три строки, когда у
     * нас ещё куча свободного места» — была «краткая, как на телефоне»). Не влезают столбцы — уходят по одному (`DROP`), как
     * в Linear и Finder: каждый вариант влезает с какой-то ширины таблицы, и `@container` по обёртке выбирает его сам — по
     * ширине самой таблицы, а не экрана, поэтому карточка рядом и узкое окно — одно правило. Отдаёт `<style>` для обёртки с
     * классом `$cls`; с открытой карточкой справа (от 1024) — своя цепочка без `aside` (это и так написано в карточке).
     *
     * @param  array<string, float>  $core  ширина, ниже которой столбец не ужимается, rem (`Widths::of`)
     * @param  array<string, float>  $full  полная — до неё растут название, код и город, пока есть место
     */
    public function gridCss(string $cls, array $core, array $full): string
    {
        $desk = array_filter($this->columns, fn (Column $c) => ! $c->phone);

        return '@media (min-width: 640px) { '.$this->chain(".{$cls}", $desk, $core, $full).' } '
            .'@media (min-width: 1024px) { '.$this->chain(".split[data-open] .{$cls}", array_filter($desk, fn (Column $c) => ! $c->aside), $core, $full, reset: true).' }';
    }

    /**
     * Набор столбцов под ширину таблицы: по важности (обратно `DROP`, главный — `Column::$main` — первым), пока влезают —
     * название ядром по 95 % значений, прочие целиком, в одну строку (лишний столбец не стоит строки в два этажа). Не
     * цепочка «убрать по одному»: ушёл широкий — вернётся неважный, которому теперь хватает места (30.10.2026, «Вывоз»:
     * ушли «Кому везти» и «Исполнитель», справа пустовала треть строки, а кто везёт — не видно). Ширины проходятся от полной
     * до нуля шагом .25rem, правило — на каждый промежуток с одним набором (`@container` с нижней и верхней границей:
     * наборы не вложены друг в друга).
     *
     * @param  array<string, Column>  $shown
     * @param  array<string, float>  $core
     * @param  array<string, float>  $full
     * @param  bool  $reset  цепочка поверх другой (с карточкой) — сначала показать свои столбцы
     */
    private function chain(string $scope, array $shown, array $core, array $full, bool $reset = false): string
    {
        $width = fn (Column $c) => $core[$c->key] ?? ($c->rem > 0 ? $c->rem : 6);
        $fixed = array_filter($shown, fn (Column $c) => $c->grow || $c->pick);
        $rest = array_values(array_filter($shown, fn (Column $c) => ! $c->grow && ! $c->pick));
        $rank = array_flip(array_map(fn (ColRole $r) => $r->value, self::DROP));
        $at = array_flip(array_map(fn (Column $c) => $c->key, $rest));
        // Важнее — раньше: главный, потом видимый с узкого экрана (`from`), потом по роли с конца `DROP`, потом объявленный раньше.
        usort($rest, fn (Column $a, Column $b) => [(int) $b->main, $a->from === null ? 0 : self::STEPS[$a->from], $rank[$b->role()->value] ?? 0, $at[$a->key]]
            <=> [(int) $a->main, $b->from === null ? 0 : self::STEPS[$b->from], $rank[$a->role()->value] ?? 0, $at[$b->key]]);

        // Прочему — полная ширина (кроме растяжки): столбец встаёт, только если строка с ним остаётся одной линией.
        $whole = fn (Column $c) => $c->fill ? $width($c) : max($full[$c->key] ?? 0, $width($c));
        $base = array_sum(array_map($width, $fixed));
        $pick = function (float $room) use ($rest, $whole, $base): array {
            $left = $room - $base;
            $keys = [];
            foreach ($rest as $c) {
                if ($whole($c) <= $left) {
                    $keys[] = $c->key;
                    $left -= $whole($c);
                }
            }

            return $keys;
        };
        // Промежутки ширин с одним набором: [от, до, ключи], сверху вниз.
        $ranges = [];
        $top = ceil(($base + array_sum(array_map($whole, $rest))) * 4) / 4;
        for ($room = $top; $room >= 0; $room -= .25) {
            $keys = $pick($room);
            if ($ranges && end($ranges)[2] === $keys) {
                $ranges[array_key_last($ranges)][0] = $room;
            } else {
                $ranges[] = [$room, $ranges ? end($ranges)[0] : null, $keys];
            }
        }
        $ranges[array_key_last($ranges)][0] = 0;

        // С карточкой — своя цепочка поверх той, что без неё: её столбцы снова видны, сколько бы та ни спрятала по ширине.
        $css = $reset ? implode(', ', array_map(fn (Column $c) => "{$scope} .col-{$c->key}", $shown)).' { display: block !important; } ' : '';
        foreach ($ranges as [$from, $below, $keys]) {
            $cols = array_filter($shown, fn (Column $c) => isset($fixed[$c->key]) || in_array($c->key, $keys, true));
            $gone = array_diff_key($shown, $cols);
            $hide = $gone ? ' '.implode(', ', array_map(fn (Column $c) => "{$scope} .col-{$c->key}", $gone)).' { display: none !important; }' : '';
            $css .= $this->variant($scope, $cols, $core, $full, $from, $below, $hide, ! $keys);
        }

        return $css;
    }

    /**
     * Вариант столбцов — в двух видах. Свободное место сетка делит поровну между растущими дорожками, а нужно по важности:
     * сначала название — по 95 % значений (ядро), потом прочие — целиком и в одну строку (код, город, «в минус …», «36 д»),
     * и только потом редкое длинное название (владельцы: «зачем сокращаем, если ещё куча места», «текст в три строки»).
     * Влезают прочие целиком — они полные, название растёт до полной длины; нет — название ядром, прочие растут, сколько
     * есть. Граница — `@container` по ширине таблицы.
     *
     * @param  array<string, Column>  $cols
     * @param  array<string, float>  $core
     * @param  array<string, float>  $full
     * @param  float  $from  вариант действует, когда таблица не уже этого (rem)
     * @param  float|null  $below  …и уже этого; null — самый полный
     * @param  string  $hide  правило «ушедшие столбцы не рисуются»
     * @param  bool  $last  кроме названия никого — оно ужимается до 12rem
     */
    private function variant(string $scope, array $cols, array $core, array $full, float $from, ?float $below, string $hide, bool $last): string
    {
        $w = fn (Column $c) => $core[$c->key] ?? ($c->rem > 0 ? $c->rem : 6);
        $roomy = round(array_sum(array_map(fn (Column $c) => $c->grow || $c->fill ? $w($c) : max($full[$c->key] ?? 0, $w($c)), $cols)), 2);
        $tight = "{$scope} .table { --cols: {$this->tracks($cols, $core, $full, false, $last)}; }";
        $wide = "{$scope} .table { --cols: {$this->tracks($cols, $core, $full, true, $last)}; }";
        $when = fn (float $lo) => implode(' and ', array_filter([$lo > 0 ? "(width >= {$lo}rem)" : null, $below !== null ? "(width < {$below}rem)" : null]));
        $at = fn (float $lo, string $rules) => ($cond = $when($lo)) === '' ? " {$rules}" : " @container {$cond} { {$rules} }";

        return $at($from, $tight.$hide).($below === null || $roomy < $below ? $at(max($from, $roomy), $wide) : '');
    }

    /**
     * Дорожки варианта: `fill` (последний столбец левой группы) забирает остаток — пустота между группами; без `fill` в
     * варианте остаток берёт название. `$roomy` — прочие влезают целиком: они полные, название растёт до полной длины;
     * иначе название — ядро, прочие растут, сколько есть.
     *
     * @param  array<string, Column>  $cols
     * @param  array<string, float>  $core
     * @param  array<string, float>  $full
     */
    private function tracks(array $cols, array $core, array $full, bool $roomy, bool $last): string
    {
        $fill = collect($cols)->contains(fn (Column $c) => $c->fill);

        return collect($cols)->map(function (Column $c) use ($core, $full, $fill, $roomy, $last) {
            $w = $core[$c->key] ?? ($c->rem > 0 ? $c->rem : 6);
            $max = max($full[$c->key] ?? $w, $w);

            return match (true) {
                // Название ужимается ниже ядра (до 12rem) только в последнем варианте — раньше уходят столбцы.
                $c->grow => 'minmax('.($last ? min($w, 12) : $w).'rem, '.($fill ? ($roomy ? $max : $w).'rem' : '1fr').')',
                // Состояние — по значениям; места мало — не шире 12rem: длиннее переносится второй строкой, а не отнимает место.
                $c->fill => 'minmax('.($c->role() === ColRole::State && ! $roomy ? max($c->rem ?: 6, min($w, 12)) : $w).'rem, 1fr)',
                $roomy => "{$max}rem",
                $max > $w => "minmax({$w}rem, {$max}rem)",
                default => "{$w}rem",
            };
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
