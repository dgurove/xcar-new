<?php

namespace App\Support\Table;

/**
 * Столбец таблицы списка (`Table`): ключ, заголовок, что это за столбец (`ColRole`: из неё место в строке, выравнивание и
 * цвет) и с какой ширины виден. Классы ячейки и заголовка — только отсюда (`classes`), строка и шапка их не пишут сами
 * (27.10.2026: шапка, строка и `colspan` групп расходились в каждом списке).
 */
final class Column
{
    /**
     * @param  string|null  $from  важность на узком: sm | md | lg | xl | 2xl — чем шире, тем раньше столбец уходит, когда
     *                             таблица не влезает (`Table::gridCss`); любой непустой — на телефоне столбца нет
     * @param  bool  $phone  только на телефоне (до 640): число строки, которое на ПК стоит своими столбцами
     * @param  string|null  $sort  ключ сортировки списка (`Support\Sort`) — заголовок нажимается
     * @param  bool  $aside  прячется, пока карточка стоит справа (`col-detail-hide`)
     * @param  string  $class  классы ячеек и заголовка; $cell — только ячеек (мелкий серый текст даты, цвет числа)
     * @param  float  $rem  сколько места столбец занимает (rem), пока нет значений, — ширину по значениям считает `Widths`
     * @param  bool  $fill  забирает запас ширины — ставит сам `Table`: последний столбец левой группы
     * @param  ColRole|null  $role  что за столбец; пусто — по флагам: `grow` — название, `pick` — галочка, `num` — сумма,
     *                              иначе — состояние
     * @param  bool  $clip  данные извне (город): ширина таблицы сеткой — по 95 % значений, редкое длинное режется «…»
     *                      (`Widths::of`); системный текст флага не получает — его не режут
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
        public readonly string $cell = '',
        public readonly float $rem = 0,
        public readonly bool $clip = false,
        public readonly ?ColRole $role = null,
    ) {}

    /** Что за столбец (`ColRole`): названа — она, иначе по флагам. */
    public function role(): ColRole
    {
        return $this->role ?? match (true) {
            $this->grow => ColRole::Title,
            $this->pick => ColRole::Pick,
            $this->num => ColRole::Amount,
            default => ColRole::State,
        };
    }

    /** Тот же столбец, забирающий (или нет) запас ширины, — его выбирает `Table`. */
    public function withFill(bool $fill): self
    {
        return $fill === $this->fill ? $this : clone ($this, ['fill' => $fill]);
    }

    /** Классы ячейки столбца; head — заголовка (без `cell`). */
    public function classes(bool $head = false): string
    {
        return trim(implode(' ', array_filter([
            // Столбец по имени — его прячет цепочка сетки (`Table::gridCss`), когда таблица уже, чем нужно.
            'col-'.$this->key,
            $this->grow ? 'grow' : null,
            // Правая группа — по правому краю, как числа (`ColRole::right`); код — приглушённым.
            $this->num || $this->role()->right() ? 'num' : null,
            $this->fill ? 'fill' : null,
            $this->dim || $this->role() === ColRole::Code ? 'cell-dim' : null,
            $this->pick ? 'pick-cell' : null,
            $this->aside ? 'col-detail-hide' : null,
            $this->phone ? 'sm:hidden' : null,
            // На телефоне столбца нет; на ПК он есть всегда, а уходит, когда таблица уже, чем нужно, — цепочка сетки
            // (`Table::gridCss`, `from` — его очередь), а не ширина экрана: рядом бывает карточка.
            $this->from === null ? null : 'hidden sm:table-cell',
            $this->class ?: null,
            $head ? null : ($this->cell ?: null),
        ])));
    }
}
