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
    /**
     * Средняя ширина знака шрифта ячейки ПК (13 px Onest), rem — замер 30.10.2026: латиница строчная .43, заглавная .54,
     * кириллица строчная .48, заглавная .58, цифра .47 (берём .5 — числа у правого края без запаса), пробел .22, знаки .26.
     */
    private const LAT = .43;

    private const LAT_UP = .54;

    private const CYR = .48;

    private const CYR_UP = .58;

    private const DIGIT = .5;

    private const SPACE = .22;

    private const NARROW = .26;

    /** Значок (svg, img: логотип, аватар) с промежутком, rem; значок в букву (`size-[1em]`: копировать, метка места) — уже. */
    private const ICON = 1.4;

    private const ICON_EM = 1.0;

    /** Поля ячейки (12 + 4 px) и число справа (поле слева 20 px), rem. */
    private const PAD = 1.1;

    /** Поля кнопки (`btn`) и чипа (`tag`, `chip`, `plate`), rem; таймер (`timer_controller`) сервер отдаёт пустым — место под «23 д 20 ч». */
    private const BTN = 2.3;

    private const CHIP = .5;

    private const TIMER = 4.4;

    private const PAD_NUM = 1.25;

    /** Запас полной ширины на ошибку оценки, rem. */
    private const SPARE = 1.0;

    /** Запас шапки сверх ячейки, rem: поле крайнего столбца (12 px против 4) и округление оценки букв. */
    private const HEAD = .5;

    /** Шире не бывает: длинное дальше режется «…». */
    public const MAX = 24;

    public const MAX_TITLE = 32;

    /**
     * @param  array<string, float>|null  $full  сюда — полная ширина (самое длинное значение): до неё сетка дорастит
     *                                           название, код и город, пока есть место, — «…» только когда места нет
     *                                           (30.10.2026, владелец: «зачем сокращаем, если у номеров убытка куча места»)
     * @return array<string, float> ключ столбца → ширина, rem (и у названия — `Table::titleKey`): не уже шапки и ядра значений — без второстепенного куска
     *                              (`.cell-sub`: «в минус …», «не оплачено …»), он при нехватке места встаёт второй
     *                              строкой, а не режется. У столбца данных извне (`Column::$clip`, город) — ядро 95 % строк:
     *                              редкое длинное значение режется «…», а не расширяет столбец всем (30.10.2026, владелец:
     *                              «неоправданно огромные расстояния», «Железногорск-Илимский» одной строкой из 739)
     */
    public static function of(string $rows, Table $table, ?array &$full = null): array
    {
        $full = [];
        $cols = array_values($table->columns);
        $values = $whole = [];
        if (preg_match_all('#<tr\b[^>]*\bdata-detail-key\b[^>]*>(.*?)</tr>#s', $rows, $trs)) {
            foreach ($trs[1] as $tr) {
                if (! preg_match_all('#<td\b[^>]*>(.*?)</td>#s', $tr, $tds)) {
                    continue;
                }
                foreach ($tds[1] as $i => $cell) {
                    $c = $cols[$i] ?? null;
                    if ($c && ! $c->pick && ! $c->phone && trim($cell) !== '') {
                        [$values[$c->key][], $whole[$c->key][]] = self::cell($cell, pieces: ! $c->grow);
                    }
                }
            }
        }

        $want = [];
        foreach ($cols as $c) {
            if ($c->pick || ($c->title === '' && ! isset($values[$c->key]))) {
                continue;
            }
            // Название и код (номер убытка, ДЛ) — данные извне, как город: по 95 % строк, редкое длинное режется «…»
            // (`--title-w` у `x-ui.table`, `.vincode-text`).
            $clip = $c->clip || $c->grow || $c->role() === ColRole::Code;
            $pad = $c->num ? self::PAD_NUM : self::PAD;
            // Шапка тоже: подпись со стрелкой сортировки бывает шире чисел столбца (шрифт шапки 12 px — на 8 % уже). Шапка не
            // режется никогда: поля у неё шире, чем у ячейки (крайний столбец — ещё и поле края), — запас `HEAD`.
            $head = $c->title === '' ? 0 : self::text($c->title) * .92 + ($c->sort ? 1.1 : 0) + self::HEAD;
            $data = 0;
            if ($v = $values[$c->key] ?? []) {
                sort($v);
                // «95 %» — на выборке от 40 строк; в коротком списке два длинных значения из двадцати — не редкость.
                $data = $clip && count($v) >= 40 ? $v[(int) floor((count($v) - 1) * .95)] : end($v);
            }
            $cap = $c->grow ? self::MAX_TITLE : self::MAX;
            $want[$c->key] = round(min(max($head, $data) + $pad, $cap), 2);
            // Полная — самое длинное значение целиком, со второстепенным куском в той же строке: есть место — он не переносится.
            // Потолок `MAX` — только ядру: полная растёт лишь в свободное место, отнять его у других не может; по той же причине
            // названию запас `SPARE` на ошибку оценки букв (у прочих полная — ещё и их ширина, лишнее отняло бы место у названия).
            $full[$c->key] = round(min(max($head, $whole[$c->key] ?? [] ? max($whole[$c->key]) : 0) + $pad + ($c->grow ? self::SPARE : 0), $cap * 2), 2);
        }

        return $want;
    }

    /**
     * Ширина ячейки, видимой на ПК, rem: ядро — текст и значки без второстепенного куска, но не уже самого широкого из них;
     * целиком — всё в одну строку.
     *
     * @return array{float, float}
     */
    private static function cell(string $html, bool $pieces = true): array
    {
        $core = '';
        $coreIcons = 0.0; // в rem
        $parts = []; // второстепенные куски: каждый встаёт второй строкой целиком — ядро не уже самого широкого
        $piece = -1;
        $hidden = $sub = 0; // глубина внутри скрытого на ПК элемента и внутри `.cell-sub`
        $stack = [];
        foreach (preg_split('#(<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)#', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if ($part[0] !== '<') {
                if (! $hidden) {
                    $sub ? $parts[$piece][0] .= $part : $core .= $part;
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
                $sub ? $parts[$piece][1] += $icon : $coreIcons += $icon;
            }
            if (in_array($name, ['img', 'br', 'input', 'use', 'path', 'source', 'wbr'], true) || str_ends_with($part, '/>')) {
                continue;
            }
            // Скрытое на ПК: классом (`sm:hidden`) или своим правилом — третий этаж краткой таблицы (`cell-source`, `cell-extra`),
            // госномер второго этажа (`sub-plate`: он уже стоит за названием).
            // Закрытое всплывающее (`popover`: меню ссылок ТС), `dialog` и `template` на экране не стоят.
            $hides = (bool) preg_match('#\bclass="[^"]*(?<![\w:-])(?:(?:sm|md|lg):hidden|cell-source|cell-extra|sub-plate)\b#', $part)
                || in_array($name, ['dialog', 'template'], true) || preg_match('#\spopover(?:[\s=>]|$)#', $part);
            $isSub = (bool) preg_match('#\bclass="[^"]*(?<![\w:-])cell-sub\b#', $part);
            if ($isSub && ! $sub && ! $hidden && ! $hides) {
                $parts[++$piece] = ['', 0.0];
            }
            $stack[] = [$hides, $isSub];
            $hidden += (int) $hides;
            $sub += (int) $isSub;
            if (! $hidden) {
                $box = match (true) {
                    (bool) preg_match('#\bclass="[^"]*(?<![\w:-])(?:btn|pill)\b#', $part) => self::BTN,
                    (bool) preg_match('#\bclass="[^"]*(?<![\w:-])(?:tag|chip|plate)\b#', $part) => self::CHIP,
                    // Аватар (до 22 px) с промежутком шире значка: сверх `ICON` его картинки или буквы.
                    (bool) preg_match('#\bclass="[^"]*(?<![\w:-])avatar\b#', $part) => self::CHIP,
                    default => 0,
                } + (str_contains($part, 'data-controller="timer"') ? self::TIMER : 0);
                $sub ? $parts[$piece][1] += $box : $coreIcons += $box;
            }
        }

        // Запас 5 % — промежутки между кусками (`ml-1.5`, `gap`, логотип и значок копирования у номера), которых в тексте нет:
        // буквы оценены точно (замер 30.10.2026), а без запаса номер убытка в «Сделках» резался на 5–7 px.
        $w = fn (string $t, float $icons) => self::text(trim(preg_replace('/\s+/u', ' ', html_entity_decode($t, ENT_QUOTES | ENT_HTML5)))) * 1.05 + $icons;

        // У названия второй этаж переносится под него сам (`.table td.grow > .cell-sub`) — ширину задаёт только ядро.
        $alone = $w($core, $coreIcons);
        $piece = array_map(fn ($p) => $w($p[0], $p[1]), $parts);

        // Целиком — куски в линию через отступ .375rem (`.table tbody td > .cell-sub + .cell-sub`), и у названия тоже: есть
        // место — «страховая до 12 окт» стоит за ним, а название не режется.
        return [max([$alone, ...($pieces ? $piece : [])]), $alone + array_sum($piece) + .375 * count($piece)];
    }

    private static function text(string $s): float
    {
        $w = 0.0;
        foreach (mb_str_split($s) as $ch) {
            $up = mb_strtolower($ch) !== $ch;
            $w += match (true) {
                $ch === ' ' || $ch === "\u{00A0}" => self::SPACE,
                str_contains('.,:;/|\'!()-·', $ch) => self::NARROW,
                ctype_digit($ch) => self::DIGIT,
                $ch === '№' => self::CYR_UP,
                $ch < "\x80" => $up ? self::LAT_UP : self::LAT,
                default => $up ? self::CYR_UP : self::CYR,
            };
        }

        return $w;
    }
}
