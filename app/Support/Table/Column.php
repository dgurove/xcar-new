<?php

namespace App\Support\Table;

/**
 * Столбец таблицы списка (`Table`): ключ, заголовок и как он стоит — с какой ширины виден, число справа, растягивается.
 * Классы ячейки и заголовка — только отсюда (`classes`), строка и шапка их не пишут сами (27.10.2026: шапка, строка и
 * `colspan` групп расходились в каждом списке).
 */
final class Column
{
    /**
     * @param  string|null  $from  с какой ширины столбец виден: sm | md | lg | xl | 2xl (пусто — всегда)
     * @param  bool  $phone  только на телефоне (до 640): число строки, которое на ПК стоит своими столбцами
     * @param  string|null  $sort  ключ сортировки списка (`Support\Sort`) — заголовок нажимается
     * @param  bool  $aside  прячется, пока карточка стоит справа (`col-detail-hide`)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title = '',
        public readonly ?string $from = null,
        public readonly bool $num = false,
        public readonly bool $fill = false,
        public readonly bool $grow = false,
        public readonly bool $dim = false,
        public readonly bool $phone = false,
        public readonly bool $pick = false,
        public readonly bool $aside = false,
        public readonly ?string $sort = null,
        public readonly string $class = '',
    ) {}

    /** Классы `td` и `th` столбца. */
    public function classes(): string
    {
        return trim(implode(' ', array_filter([
            $this->grow ? 'grow' : null,
            $this->num ? 'num' : null,
            $this->fill ? 'fill' : null,
            $this->dim ? 'cell-dim' : null,
            $this->pick ? 'pick-cell' : null,
            $this->aside ? 'col-detail-hide' : null,
            $this->phone ? 'sm:hidden' : null,
            // Полные строки классов — их видит сборка CSS (`@source` в app.css).
            match ($this->from) {
                'sm' => 'hidden sm:table-cell',
                'md' => 'hidden md:table-cell',
                'lg' => 'hidden lg:table-cell',
                'xl' => 'hidden xl:table-cell',
                '2xl' => 'hidden 2xl:table-cell',
                default => null,
            },
            $this->class ?: null,
        ])));
    }
}
