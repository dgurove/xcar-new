<?php

namespace App\Support\Facets;

use App\Support\Plural;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Чипы фильтров списка (03.10.2026, владелец: «текстовое поле — жалкое подобие фильтра»). Контроллер объявляет чипы,
 * отдаёт базовый запрос (пресет уже наложен) в apply(), вид рисует ряд `x-ui.facets`. В шторке чипа — только то, что
 * есть в списке с прочими чипами, числом справа; «Показать N» спрашивает число тем же адресом с заголовком `X-Count`.
 * Поиск (`q`) идёт мимо чипов: пока он набран, apply() ничего не сужает. Живёт один запрос — статики нет (Octane).
 */
final class Facets
{
    /** @var array<string, Facet> */
    private array $facets = [];

    private ?Builder $base = null;

    private ?Builder $applied = null;

    private string $countExpr = 'count(*)';

    private ?Collection $chips = null;

    private ?int $total = null;

    private ?string $path = null;

    private bool $always = false;

    /** @var array<string, list<string>> исключение, развёрнутое в варианты (для своего условия `apply`) */
    private array $resolved = [];

    public function __construct(private Request $request, public string $list, Facet ...$facets)
    {
        foreach ($facets as $f) {
            $this->facets[$f->key] = $f;
        }
    }

    public static function for(Request $request, string $list, Facet ...$facets): self
    {
        return new self($request, $list, ...$facets);
    }

    /** Чипы видны всегда, даже с одним вариантом: у вкладок с парой строк фильтр иначе пропадал, и неясно, почему
     * строк мало (владелец 05.10.2026, предложения CRM). Без вариантов и выбора чипа всё равно нет. */
    public function always(): self
    {
        $this->always = true;

        return $this;
    }

    /** Адрес списка, если экран другой (чаты: справа открыт чат, а фильтр — у списка). */
    public function at(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function action(): string
    {
        return $this->path ?? '/'.ltrim($this->request->path(), '/');
    }

    /** Что считать строкой списка — у почты дело, а не ветка. */
    public function countBy(string $expr): self
    {
        $this->countExpr = $expr;

        return $this;
    }

    /** @return list<string> ключи адреса — ListPrefs их помнит */
    public function keys(): array
    {
        return array_keys($this->facets);
    }

    public function has(string $key): bool
    {
        return isset($this->facets[$key]);
    }

    public function searching(): bool
    {
        $q = $this->request->query('q');

        return is_string($q) && trim($q) !== '';
    }

    /** Исключённое (`?vendor=!22`) или null — выбор обычный. @return list<string>|null */
    public function excluded(string $key): ?array
    {
        $raw = $this->request->query($key);
        if (! is_string($raw) || ! str_starts_with($raw, '!') || ! isset($this->facets[$key]) || $this->facets[$key]->toggle || $this->facets[$key]->single) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', substr($raw, 1))), fn ($v) => $v !== '')));
    }

    /** @return list<string> выбранное; у исключения — все варианты списка, кроме исключённых */
    public function selected(string $key): array
    {
        $raw = $this->request->query($key);
        if (! is_string($raw) || $raw === '' || ! isset($this->facets[$key])) {
            return [];
        }
        if (($out = $this->excluded($key)) !== null) {
            return $out === [] || $this->base === null ? [] : $this->resolved[$key] ??= array_values(array_diff(
                array_map('strval', array_keys($this->facets[$key]->counts(clone $this->base, $this->countExpr))), $out));
        }
        if ($this->facets[$key]->toggle) {
            return $raw === '0' ? [] : ['1'];
        }
        if ($this->facets[$key]->single) {
            return $raw === $this->facets[$key]->default ? [] : [$raw];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== '')));
    }

    public function on(string $key): bool
    {
        return $this->excluded($key) !== null || $this->selected($key) !== [];
    }

    public function active(): bool
    {
        return array_any($this->keys(), fn ($k) => $this->on($k));
    }

    /**
     * Сузить запрос списка выбранным. Запрос «сколько будет» (`X-Count`) отвечает здесь же числом — до страниц,
     * подгрузок и счётчиков пилюль.
     */
    public function apply(Builder|Relation $q): Builder|Relation
    {
        $b = $q instanceof Relation ? $q->getQuery() : $q;
        $this->base = clone $b;
        if (! $this->searching()) {
            foreach ($this->facets as $key => $f) {
                $this->narrow($b, $key, $f);
            }
        }
        $this->applied = clone $b;
        if ($this->request->headers->has('X-Count')) {
            throw new HttpResponseException(response()->json(['count' => $this->total()]));
        }

        return $q;
    }

    /** Тот же выбор на другом запросе — счётчики пилюль; $except — чип, который не учитывать. */
    public function applyTo(Builder|Relation $q, ?string $except = null): Builder|Relation
    {
        $b = $q instanceof Relation ? $q->getQuery() : $q;
        if (! $this->searching()) {
            foreach ($this->facets as $key => $f) {
                if ($key !== $except) {
                    $this->narrow($b, $key, $f);
                }
            }
        }

        return $q;
    }

    /** Колонка с исключением — `not in` (новый вендор появился — он в списке); своё условие — развёрнутым выбором. */
    private function narrow(Builder $b, string $key, Facet $f): void
    {
        $out = $this->excluded($key);
        $out !== null && $f->apply === null ? $f->exclude($b, $out) : $f->constrain($b, $this->selected($key));
    }

    /** Сколько строк при текущем выборе — начальное число на «Показать N». */
    public function total(): int
    {
        if ($this->total === null) {
            $q = Facet::bare(($this->applied ?? $this->base)?->toBase());
            $this->total = $this->countExpr === 'count(*)' ? $q->getCountForPagination() : (int) $q->selectRaw("{$this->countExpr} as n")->value('n');
        }

        return $this->total;
    }

    /**
     * Чипы для ряда: у каждого варианты на базе с прочими выбранными чипами. Невыбранный чип с одним вариантом не
     * рисуется — сужать нечего; выбранное значение с нулём остаётся, чтобы его можно было снять.
     *
     * @return Collection<int, object{facet: Facet, options: list<Option>, selected: list<string>, label: string}>
     */
    public function chips(): Collection
    {
        if ($this->chips !== null) {
            return $this->chips;
        }
        if ($this->base === null) {
            return $this->chips = collect();
        }

        return $this->chips = collect($this->facets)->map(function (Facet $f, string $key) {
            $selected = $this->selected($key);
            if ($f->toggle) {
                $n = $f->tally ? ($f->tally)() : null;
                if ($n === 0 && ! $selected) {
                    return null;
                }

                return (object) ['facet' => $f, 'options' => [], 'selected' => $selected, 'label' => $f->title, 'count' => $n];
            }
            $sub = clone $this->base;
            $this->applyTo($sub, $key);
            $counts = $f->counts($sub, $this->countExpr);
            $out = $this->excluded($key);
            // Исключённые — в шторке без галки (даже с нулём: вернуть), остальные варианты этого списка — с галкой.
            if ($out !== null) {
                $selected = array_values(array_diff(array_map('strval', array_keys($counts)), $out));
            }
            foreach ([...$selected, ...($out ?? [])] as $s) {
                $counts[$s] ??= 0;
            }

            $keys = array_map('strval', array_keys($counts));
            $named = $f->labels ? ($f->labels)(array_values(array_diff($keys, ['none']))) : [];
            $options = [];
            foreach ($counts as $k => $n) {
                $k = (string) $k;
                $o = $k === 'none' ? new Option('none', (string) $f->none) : ($named[$k] ?? ($f->labels ? null : new Option($k, $k)));
                if (! $o) {
                    continue;
                }
                $o->count = $n;
                $o->selected = in_array($k, $selected, true) || ($f->single && ! $selected && $k === $f->default);
                // Пустой вариант в шторке не нужен — разве что он выбран и его надо снять (и «Все» одиночного выбора).
                if ($n === 0 && ! $o->selected && $k !== $f->default) {
                    continue;
                }
                $options[$k] = $o;
            }
            if ($f->natural) {
                $order = array_flip([...array_keys($named), 'none']);
                uksort($options, fn ($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
            } else {
                uasort($options, fn (Option $a, Option $b) => [$b->count, $a->key === 'none' ? 1 : 0, $a->label] <=> [$a->count, $b->key === 'none' ? 1 : 0, $b->label]);
            }
            // Сужать нечего — чипа нет. Но ряд не прыгает от соседних чипов: если без них вариантов больше одного,
            // чип стоит и с одним вариантом.
            if ($this->always && ! $selected && $out === null && $options === []) {
                return null;
            }
            if (! $this->always && ! $selected && count(array_filter($options, fn (Option $o) => $o->count > 0)) < 2
                && ($f->count || ! $this->active() || count(array_filter($f->counts(clone $this->base, $this->countExpr))) < 2)) {
                return null;
            }
            $picked = array_values(array_filter($options, fn (Option $o) => $o->selected && $o->key !== $f->default));
            // Почти всё отмечено — человек исключал: «Кроме Каркаде», а не «4 вендора».
            $left = array_values(array_filter($options, fn (Option $o) => ! $o->selected));
            $outNames = collect($options)->only($out ?? [])->map(fn (Option $o) => $o->label)->values();
            $label = match (true) {
                $out !== null && $outNames->isNotEmpty() && $outNames->count() <= 2 => 'Кроме '.$outNames->implode(', '),
                count($picked) === 1 => $picked[0]->label,
                ! $f->single && count($picked) >= 2 && $left && count($left) <= 2 && count($left) < count($picked) => 'Кроме '.implode(', ', array_map(fn (Option $o) => $o->label, $left)),
                count($picked) > 1 => count($picked).' '.Plural::of(count($picked), $f->plural),
                default => $f->title,
            };

            // Исключение помнит, кого исключили, — шторка отправляет его тем же видом (`!22`), не списком оставшихся.
            $on = $selected !== [] || $out !== null;

            return (object) ['facet' => $f, 'options' => array_values($options), 'selected' => $selected, 'on' => $on, 'value' => $out !== null ? '!'.implode(',', $out) : implode(',', $selected), 'label' => $label];
        })->filter()->values();
    }

    /** Адрес с заменой: '' остаётся в адресе — это «снять и забыть» для ListPrefs. */
    public function url(array $set): string
    {
        // Карточка строки (?peek=) после смены фильтра не открывается заново: открытая строка могла из списка уйти.
        $query = array_merge($this->request->query(), $set, ['page' => null, 'peek' => null]);
        $query = array_filter($query, fn ($v) => $v !== null && (is_string($v) || is_int($v)));

        return $this->action().($query ? '?'.str_replace('%2C', ',', http_build_query($query)) : '');
    }

    /** Ссылка «Сбросить»: все выбранные чипы — пустыми; $also — что снять с ними заодно (пилюля, поиск). */
    public function resetUrl(array $also = []): string
    {
        return $this->url(array_fill_keys(array_filter($this->keys(), fn ($k) => $this->on($k)), '') + $also);
    }

    /** @return array<string, string> параметры адреса, которые форма шторки чипа несёт скрытыми полями */
    public function carry(string $except): array
    {
        return array_filter($this->request->query(), fn ($v, $k) => is_string($v) && $v !== '' && ! in_array($k, [$except, 'page', 'q', 'peek'], true), ARRAY_FILTER_USE_BOTH);
    }
}
