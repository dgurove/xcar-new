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

    public function __construct(Column ...$columns)
    {
        $keyed = [];
        foreach ($columns as $c) {
            $keyed[$c->key] = $c;
        }
        $this->columns = $keyed;
    }

    /** Столбцы по условию: `null` в списке — столбца нет. */
    public static function of(?Column ...$columns): self
    {
        return new self(...array_values(array_filter($columns)));
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
