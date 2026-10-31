<?php

namespace App\Park;

/**
 * Схема парковки (31.10.2026, владелец: «графически видеть, где машина, и переставлять»): прямые ряды мест в метрах.
 * Ряд — `{id, name, from, n, x, y, dir, w, l, holes, rev}`: места бок о бок вправо (`h`) или вниз (`v`), размер места
 * `w × l` на весь ряд, `holes` — убранные места по порядку в ряду (0 — первое от начала; столб, въезд; номера остальных не
 * сдвигаются — таблички на площадке те же, а нумерация «с конца» или с другого номера не переносит убранное на другое место),
 * `from` — первый номер, `rev` — нумерация с другого конца. Ряды «A: 20» без координат раскладываются стопкой через проезд.
 * Та же геометрия — в `resources/js/park/plan/geometry.js` (редактор).
 */
final class Plan
{
    public const W = 2.5;

    public const L = 5.0;

    public const AISLE = 6.0;

    public const MAX_N = 200;

    /** Ряды схемы в полном виде; старые без координат — стопкой сверху вниз. @return list<array> */
    public static function rows(?array $rows): array
    {
        $out = [];
        $y = 0.0;
        foreach (array_values($rows ?? []) as $i => $r) {
            $n = max(0, min(self::MAX_N, (int) ($r['n'] ?? 0)));
            if (! $n) {
                continue;
            }
            $row = [
                'id' => (string) ($r['id'] ?? 'r'.($i + 1)),
                'name' => mb_strtoupper(trim((string) ($r['name'] ?? ''))),
                'from' => max(1, (int) ($r['from'] ?? 1)),
                'n' => $n,
                'x' => (float) ($r['x'] ?? 0),
                'y' => isset($r['y']) ? (float) $r['y'] : $y,
                'dir' => ($r['dir'] ?? 'h') === 'v' ? 'v' : 'h',
                'w' => (float) ($r['w'] ?? self::W),
                'l' => (float) ($r['l'] ?? self::L),
                'holes' => array_values(array_unique(array_map('intval', $r['holes'] ?? []))),
                'rev' => (bool) ($r['rev'] ?? false),
            ];
            [, , , $bottom] = self::box($row);
            $y = max($y, $bottom + self::AISLE);
            $out[] = $row;
        }

        return $out;
    }

    public static function code(string $name, int $num): string
    {
        return $name === '' ? (string) $num : $name.'-'.$num;
    }

    /** Номер места по порядку в ряду (0 — первое от начала ряда). */
    public static function num(array $row, int $k): int
    {
        return $row['rev'] ? $row['from'] + $row['n'] - 1 - $k : $row['from'] + $k;
    }

    /** Места ряда без убранных: порядок в ряду, код, номер и прямоугольник. @return list<array{k: int, code: string, num: int, x: float, y: float, w: float, h: float}> */
    public static function spots(array $row): array
    {
        $spots = [];
        for ($k = 0; $k < $row['n']; $k++) {
            if (in_array($k, $row['holes'], true)) {
                continue;
            }
            $num = self::num($row, $k);
            $spots[] = ['k' => $k, 'code' => self::code($row['name'], $num), 'num' => $num] + ($row['dir'] === 'h'
                ? ['x' => $row['x'] + $k * $row['w'], 'y' => $row['y'], 'w' => $row['w'], 'h' => $row['l']]
                : ['x' => $row['x'], 'y' => $row['y'] + $k * $row['w'], 'w' => $row['l'], 'h' => $row['w']]);
        }

        return $spots;
    }

    /** Коды мест ряда по номерам: номер → код. @return array<int, string> */
    public static function codes(array $row): array
    {
        $codes = array_column(self::spots($row), 'code', 'num');
        ksort($codes);

        return $codes;
    }

    /** [x0, y0, x1, y1] ряда. */
    public static function box(array $row): array
    {
        $long = $row['n'] * $row['w'];

        return $row['dir'] === 'h'
            ? [$row['x'], $row['y'], $row['x'] + $long, $row['y'] + $row['l']]
            : [$row['x'], $row['y'], $row['x'] + $row['l'], $row['y'] + $long];
    }

    /** [x0, y0, x1, y1] всей схемы; пустая — null. */
    public static function bounds(array $rows): ?array
    {
        if (! $rows) {
            return null;
        }
        $boxes = array_map(self::box(...), $rows);

        return [min(array_column($boxes, 0)), min(array_column($boxes, 1)), max(array_column($boxes, 2)), max(array_column($boxes, 3))];
    }
}
