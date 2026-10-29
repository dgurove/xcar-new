<?php

namespace App\Support\Table;

/**
 * Ширины столбцов таблицы сеткой — один раз на сервере, по самим значениям (30.10.2026). Раньше столбец ширил скрипт:
 * мерил каждую строку, подходящую к экрану, прямо во время прокрутки (владелец: «это нагружает рендер»), а в Safari не
 * ширил вовсе. Как в нормальных таблицах: ширина известна до отрисовки и при прокрутке не меняется.
 *
 * Берём готовый HTML строк (слот `x-ui.table`), в каждой ячейке — текст, видимый на ПК (куски `sm:hidden` и шире не
 * считаются), и значки; длину переводим в rem по шрифту ячейки ПК (13 px Onest, `table-wrap .table`) с небольшим запасом.
 * Название, `fill` и галочка не считаются: им остаток ширины.
 */
final class Widths
{
    /** Средняя ширина знака, rem: заглавная, цифра, узкий знак, прочее (замер 30.10.2026 — с запасом ~5 %). */
    private const UPPER = .6;

    private const DIGIT = .5;

    private const NARROW = .25;

    private const OTHER = .47;

    /** Значок (svg, img: логотип, аватар) с промежутком, rem; значок в букву (`size-[1em]`: копировать, метка места) — уже. */
    private const ICON = 1.4;

    private const ICON_EM = 1.0;

    /** Поля ячейки (12 + 4 px) и число справа (поле слева 20 px), rem. */
    private const PAD = 1.0;

    private const PAD_NUM = 1.25;

    /** Шире не бывает: длинное дальше режется «…». */
    public const MAX = 24;

    /**
     * @return array<string, float> ключ столбца → ширина, rem: не уже шапки и ядра значений — без второстепенного куска
     *                              (`.cell-sub`: «в минус …», «не оплачено …»), он при нехватке места встаёт второй
     *                              строкой, а не режется. У столбца данных извне (`Column::$clip`, город) — ядро 95 % строк:
     *                              редкое длинное значение режется «…», а не расширяет столбец всем (30.10.2026, владелец:
     *                              «неоправданно огромные расстояния», «Железногорск-Илимский» одной строкой из 739)
     */
    public static function of(string $rows, Table $table): array
    {
        $cols = array_values($table->columns);
        $values = [];
        if (preg_match_all('#<tr\b[^>]*\bdata-detail-key\b[^>]*>(.*?)</tr>#s', $rows, $trs)) {
            foreach ($trs[1] as $tr) {
                if (! preg_match_all('#<td\b[^>]*>(.*?)</td>#s', $tr, $tds)) {
                    continue;
                }
                foreach ($tds[1] as $i => $cell) {
                    $c = $cols[$i] ?? null;
                    if ($c && ! $c->grow && ! $c->fill && ! $c->pick && ! $c->phone && trim($cell) !== '') {
                        $values[$c->key][] = self::cell($cell);
                    }
                }
            }
        }

        $want = [];
        foreach ($cols as $c) {
            if ($c->grow || $c->fill || $c->pick || ($c->title === '' && ! isset($values[$c->key]))) {
                continue;
            }
            $pad = $c->num ? self::PAD_NUM : self::PAD;
            // Шапка тоже: подпись со стрелкой сортировки бывает шире чисел столбца (шрифт шапки 12 px — на 8 % уже).
            $head = $c->title === '' ? 0 : self::text($c->title) * .92 + ($c->sort ? 1 : 0);
            $data = 0;
            if ($v = $values[$c->key] ?? []) {
                sort($v);
                $data = $c->clip ? $v[(int) floor((count($v) - 1) * .95)] : end($v);
            }
            $want[$c->key] = round(min(max($head, $data) + $pad, self::MAX), 2);
        }

        return $want;
    }

    /** Ширина ядра ячейки, видимого на ПК, rem: текст и значки без второстепенного куска, но не уже самого широкого из них. */
    private static function cell(string $html): float
    {
        $core = '';
        $coreIcons = 0.0; // в rem
        $pieces = []; // второстепенные куски: каждый встаёт второй строкой целиком — ядро не уже самого широкого
        $piece = -1;
        $hidden = $sub = 0; // глубина внутри скрытого на ПК элемента и внутри `.cell-sub`
        $stack = [];
        foreach (preg_split('#(<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)#', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if ($part[0] !== '<') {
                if (! $hidden) {
                    $sub ? $pieces[$piece][0] .= $part : $core .= $part;
                }

                continue;
            }
            if ($part[1] === '/') {
                [$hides, $isSub] = array_pop($stack) ?? [false, false];
                $hidden -= (int) $hides;
                $sub -= (int) $isSub;

                continue;
            }
            if (! preg_match('#^<([a-z0-9-]+)#i', $part, $tag)) {
                continue;
            }
            $name = strtolower($tag[1]);
            if (! $hidden && ($name === 'svg' || $name === 'img')) {
                $icon = str_contains($part, 'size-[1em]') ? self::ICON_EM : self::ICON;
                $sub ? $pieces[$piece][1] += $icon : $coreIcons += $icon;
            }
            if (in_array($name, ['img', 'br', 'input', 'use', 'path', 'source', 'wbr'], true) || str_ends_with($part, '/>')) {
                continue;
            }
            $hides = (bool) preg_match('#\bclass="[^"]*(?<![\w:-])(?:sm|md|lg):hidden\b#', $part);
            $isSub = (bool) preg_match('#\bclass="[^"]*(?<![\w:-])cell-sub\b#', $part);
            if ($isSub && ! $sub && ! $hidden && ! $hides) {
                $pieces[++$piece] = ['', 0.0];
            }
            $stack[] = [$hides, $isSub];
            $hidden += (int) $hides;
            $sub += (int) $isSub;
        }

        // Запас 5 % — промежутки между кусками (`ml-1.5`, `gap`), которых в тексте нет.
        $w = fn (string $t, float $icons) => self::text(trim(preg_replace('/\s+/u', ' ', html_entity_decode($t, ENT_QUOTES | ENT_HTML5)))) * 1.05 + $icons;

        return max([$w($core, $coreIcons), ...array_map(fn ($p) => $w($p[0], $p[1]), $pieces)]);
    }

    private static function text(string $s): float
    {
        $w = 0.0;
        foreach (mb_str_split($s) as $ch) {
            $w += match (true) {
                $ch === ' ' || $ch === "\u{00A0}" || str_contains('.,:;/|\'!()-·', $ch) => self::NARROW,
                ctype_digit($ch) => self::DIGIT,
                $ch === '№' || mb_strtolower($ch) !== $ch => self::UPPER,
                default => self::OTHER,
            };
        }

        return $w;
    }
}
