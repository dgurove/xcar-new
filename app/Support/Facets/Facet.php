<?php

namespace App\Support\Facets;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Один чип фильтра списка. Три вида:
 * - column — у строки одно значение (колонка или скалярный подзапрос): варианты и числа — group by по нему;
 * - custom — своё условие и свой подсчёт (многие ко многим: группы, ТС закупки с ценой менеджера);
 * - toggle — переключатель без шторки («Непрочитанные», «Мои»).
 * Значения в адресе — через запятую (`?vendor=3,5`): одно значение выглядит как прежние `?yard=2`, ссылки живут.
 * С `!` впереди — исключение (`?vendor=!22` — все, кроме Каркаде): так фильтр «кроме» одинаков на любой вкладке, а не
 * застывает списком вендоров, которые были на той, где его выбрали.
 */
final class Facet
{
    public ?string $expr = null;

    public ?Closure $apply = null;

    public ?Closure $count = null;

    public ?Closure $labels = null;

    public ?string $none = null;

    public bool $toggle = false;

    /** Значения — числа (id): в запрос идут целыми, чужое в адресе отбрасывается, а не роняет запрос ошибкой типа. */
    public bool $numeric = false;

    public bool $single = false;

    /** Вариант одиночного выбора, которого нет в адресе («Все ТС»). */
    public ?string $default = null;

    /** Порядок вариантов — как отдают подписи (этапы гаража по ходу дела), а не по числу. */
    public bool $natural = false;

    public ?string $tone = null;

    /** Переключатель с числом («Без ставки 4»): нуль — переключателя нет, пока он не включён. */
    public ?Closure $tally = null;

    /** @var array<string, list<string>> подпись группы → ключи (шторка «Какие ТС» закупки) */
    public array $groups = [];

    /** @param array{0: string, 1: string, 2: string} $plural «2 менеджера» у чипа с несколькими значениями */
    private function __construct(public string $key, public string $title, public array $plural = []) {}

    public static function column(string $key, string $title, array $plural, string $expr): self
    {
        $f = new self($key, $title, $plural);
        $f->expr = $expr;

        return $f;
    }

    /**
     * @param  Closure(Builder, list<string>): void  $apply
     * @param  Closure(Builder): array<string, int>  $count  ключ → число строк на базе с прочими чипами
     */
    public static function custom(string $key, string $title, array $plural, Closure $apply, Closure $count): self
    {
        $f = new self($key, $title, $plural);
        $f->apply = $apply;
        $f->count = $count;

        return $f;
    }

    /** @param Closure(Builder): void $apply */
    public static function toggle(string $key, string $title, Closure $apply): self
    {
        $f = new self($key, $title);
        $f->toggle = true;
        $f->apply = fn (Builder $q) => $apply($q);

        return $f;
    }

    /** Вариант «пусто» (`none` в адресе): «Без парковки», «Взяли под себя». */
    public function none(string $label): self
    {
        $this->none = $label;

        return $this;
    }

    /** @param Closure(list<string>): array<string, Option> $fn */
    public function labels(Closure $fn): self
    {
        $this->labels = $fn;

        return $this;
    }

    /** Подписи из enum: label(), иначе short(); неизвестные значения в базе не показываются. */
    public function enum(string $class, string $method = 'label'): self
    {
        return $this->labels(function (array $keys) use ($class, $method) {
            $out = [];
            foreach ($class::cases() as $case) {
                if (in_array((string) $case->value, $keys, true)) {
                    $out[(string) $case->value] = new Option((string) $case->value, $case->{$method}());
                }
            }

            return $out;
        });
    }

    public function numeric(): self
    {
        $this->numeric = true;

        return $this;
    }

    public function single(?string $default = null): self
    {
        $this->single = true;
        $this->default = $default;

        return $this;
    }

    public function natural(): self
    {
        $this->natural = true;

        return $this;
    }

    /** @param Closure(): int $fn */
    public function counted(Closure $fn): self
    {
        $this->tally = $fn;

        return $this;
    }

    /** Тон невключённого переключателя (pill-danger у «Без ставки»). */
    public function tone(string $class): self
    {
        $this->tone = $class;

        return $this;
    }

    public function groups(array $groups): self
    {
        $this->groups = $groups;

        return $this;
    }

    /** Сузить запрос выбранными значениями. */
    public function constrain(Builder $q, array $values): void
    {
        if ($values === []) {
            return;
        }
        if ($this->apply) {
            $this->toggle ? ($this->apply)($q) : ($this->apply)($q, $values);

            return;
        }
        $none = $this->none !== null && in_array('none', $values, true);
        $keys = array_values(array_filter($values, fn ($v) => $v !== 'none' && (! $this->numeric || ctype_digit($v))));
        if ($this->numeric) {
            $keys = array_map('intval', $keys);
        }
        // Колонка сравнивается как есть, без приведения типа: так работает индекс (вендор, парковка, тип ТС).
        $q->where(function (Builder $w) use ($keys, $none) {
            if ($keys) {
                $w->whereRaw("{$this->expr} in (".implode(',', array_fill(0, count($keys), '?')).')', $keys);
            }
            if ($none) {
                $w->orWhereRaw("({$this->expr}) is null");
            }
            if (! $keys && ! $none) {
                $w->whereRaw('false');
            }
        });
    }

    /** Исключение (`!22`): всё, кроме этих. Своё условие (`apply`) получает список оставшихся — его считает Facets. */
    public function exclude(Builder $q, array $values): void
    {
        $none = $this->none !== null && in_array('none', $values, true);
        $keys = array_values(array_filter($values, fn ($v) => $v !== 'none' && (! $this->numeric || ctype_digit($v))));
        if ($this->numeric) {
            $keys = array_map('intval', $keys);
        }
        $q->where(function (Builder $w) use ($keys, $none) {
            if ($keys) {
                $w->whereRaw("{$this->expr} not in (".implode(',', array_fill(0, count($keys), '?')).')', $keys);
                // NULL в «not in» не проходит — «Без вендора» остаётся, пока его не исключили.
                $none || $w->orWhereRaw("({$this->expr}) is null");
            } elseif ($none) {
                $w->whereRaw("({$this->expr}) is not null");
            }
        });
    }

    /** @return array<string, int> ключ → число (null — `none`, если вариант «пусто» есть) */
    public function counts(Builder $q, string $countExpr): array
    {
        if ($this->count) {
            return ($this->count)($q);
        }
        $rows = self::bare($q->toBase())
            ->selectRaw("{$this->expr} as v, {$countExpr} as n")
            ->groupByRaw('1')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            if ($row->v === null) {
                if ($this->none !== null) {
                    $out['none'] = (int) $row->n;
                }

                continue;
            }
            $out[(string) $row->v] = (int) $row->n;
        }

        return $out;
    }

    /** Запрос без колонок, сортировки и страниц списка — для group by и count. */
    public static function bare(QueryBuilder $q): QueryBuilder
    {
        return $q->cloneWithout(['columns', 'orders', 'limit', 'offset', 'unionOrders', 'unionLimit', 'unionOffset'])
            ->cloneWithoutBindings(['select', 'order']);
    }
}
