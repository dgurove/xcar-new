<?php

namespace App\Park\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Park\Plan;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Схема парковки из редактора целиком (`Plan`). Сохраняется поверх той ревизии, что открыта, — иначе «Схему поменяли».
 * Место, где стоит ТС, не убирается; ряд переименовали или сдвинули нумерацию — у стоящих в нём ТС место переписывается
 * (по id ряда и порядку места в нём). Вместимость — число мест.
 */
final class SavePlan
{
    /** @param  list<array>  $rows  ряды из редактора */
    public function __invoke(Yard $yard, array $rows, string $rev): Yard
    {
        $rows = self::clean($rows);

        $yard = DB::transaction(function () use ($yard, $rows, $rev) {
            // Замки в порядке перестановки (`Move`: ТС, потом парковка), иначе схема и перестановка ждали бы друг друга вечно.
            $stored = Vehicle::where('yard_id', $yard->id)->where('state', VehicleState::Stored)->whereNotNull('spot')->orderBy('id')->lockForUpdate()->get();
            $yard = Yard::whereKey($yard->id)->lockForUpdate()->firstOrFail();
            if ($yard->planRev() !== $rev) {
                throw ValidationException::withMessages(['plan' => 'Схему поменяли, откройте её заново']);
            }

            // Каждое место старой схемы → его код в новой: тот же ряд, то же место по порядку от начала ряда; ряда нет, ряд
            // стал короче или место убрано — null. Убранные места тоже по порядку (`Plan`), поэтому нумерация «с конца» или
            // с другого номера их не сдвигает.
            $new = collect($rows)->keyBy('id');
            $target = [];
            foreach ($yard->plan() as $old) {
                $row = $new->get($old['id']);
                foreach (Plan::spots($old) as $s) {
                    $target[$s['code']] = $row && $s['k'] < $row['n'] && ! in_array($s['k'], $row['holes'], true) ? Plan::code($row['name'], Plan::num($row, $s['k'])) : null;
                }
            }
            $spots = array_merge([], ...array_map(fn (array $row) => array_values(Plan::codes($row)), $rows));

            $stored->load('car.brand');
            $name = fn (Vehicle $v) => $v->brand?->name ?? $v->ref ?? 'ТС';
            $moving = collect();
            $taken = [];
            foreach ($stored as $v) {
                if (! array_key_exists($v->spot, $target)) {
                    // Место свободным текстом (не из старой схемы) остаётся как есть.
                    $taken[$v->spot] = $v;

                    continue;
                }
                if ($target[$v->spot] === null) {
                    throw ValidationException::withMessages(['plan' => 'На месте '.$v->spot.' стоит '.$name($v).', сначала переставьте её']);
                }
                if ($target[$v->spot] !== $v->spot) {
                    $moving->push($v);
                }
            }
            // Новый код места уже у ТС, записанной свободным текстом, — двух машин на одном месте не бывает.
            foreach ($moving as $v) {
                if ($other = $taken[$target[$v->spot]] ?? null) {
                    throw ValidationException::withMessages(['plan' => 'Место '.$target[$v->spot].' уже записано за '.$name($other).', поменяйте ей место']);
                }
            }

            // Два прохода: A→B и B→A разом упёрлись бы в уникальность места.
            foreach ($moving as $v) {
                Vehicle::whereKey($v->id)->update(['spot' => '#'.$v->id]);
            }
            foreach ($moving as $v) {
                $v->update(['spot' => $target[$v->spot]]);
            }

            $yard->update(['rows' => $rows] + ($rows ? ['capacity' => count($spots)] : []));

            return $yard;
        });

        app(Publisher::class)->refresh(Topics::PARK, ['/yards', "/yards/{$yard->id}/plan"]);

        return $yard;
    }

    /** Ряды в проверенном виде: размеры в разумных пределах, координаты по шагу 0,25 м, коды без повторов. */
    private static function clean(array $rows): array
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['plan' => $m]);
        if (count($rows) > 100) {
            $fail('Рядов больше сотни');
        }
        $snap = fn ($v) => round(((float) $v) * 4) / 4;
        $out = [];
        $ids = [];
        foreach (array_values($rows) as $r) {
            if (! is_array($r)) {
                $fail('Схема не прочиталась');
            }
            $id = (string) ($r['id'] ?? '');
            $name = mb_strtoupper(trim((string) ($r['name'] ?? '')));
            if (! preg_match('/^[\w-]{1,16}$/', $id) || isset($ids[$id])) {
                $fail('Схема не прочиталась');
            }
            $ids[$id] = true;
            if (! preg_match('/^[\p{Lu}\d]{0,3}$/u', $name)) {
                $fail('Ряд «'.$name.'»: до трёх букв или цифр');
            }
            $n = (int) ($r['n'] ?? 0);
            if ($n < 1 || $n > Plan::MAX_N) {
                $fail('В ряду '.($name ?: 'без буквы').' от 1 до '.Plan::MAX_N.' мест');
            }
            $from = (int) ($r['from'] ?? 1);
            // Убранные места — по порядку в ряду (0 — первое от начала), не по номеру.
            $holes = array_values(array_unique(array_map('intval', is_array($r['holes'] ?? null) ? $r['holes'] : [])));
            $row = [
                'id' => $id, 'name' => $name, 'from' => max(1, min(9999, $from)), 'n' => $n,
                'x' => max(-2000, min(2000, $snap($r['x'] ?? 0))), 'y' => max(-2000, min(2000, $snap($r['y'] ?? 0))),
                'dir' => ($r['dir'] ?? 'h') === 'v' ? 'v' : 'h',
                'w' => max(1.5, min(8, $snap($r['w'] ?? Plan::W))), 'l' => max(2.5, min(25, $snap($r['l'] ?? Plan::L))),
                'rev' => (bool) ($r['rev'] ?? false),
            ];
            $row['holes'] = array_values(array_filter($holes, fn ($h) => $h >= 0 && $h < $n));
            if (count($row['holes']) >= $n) {
                $fail('В ряду '.($name ?: 'без буквы').' не осталось мест');
            }
            $out[] = $row;
        }
        $seen = [];
        foreach ($out as $row) {
            foreach (Plan::codes($row) as $code) {
                if (isset($seen[$code])) {
                    $fail('Место '.$code.' повторяется в двух рядах');
                }
                $seen[$code] = true;
            }
        }

        return $out;
    }
}
