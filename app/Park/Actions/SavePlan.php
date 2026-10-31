<?php

namespace App\Park\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Park\Plan;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Nav;
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
            $yard = Yard::whereKey($yard->id)->lockForUpdate()->firstOrFail();
            if ($yard->planRev() !== $rev) {
                throw ValidationException::withMessages(['plan' => 'Схему поменяли, откройте её заново']);
            }

            // Старое место → новое: тот же ряд, то же место по порядку от начала ряда.
            $renamed = [];
            $new = collect($rows)->keyBy('id');
            foreach ($yard->plan() as $old) {
                if (! $row = $new->get($old['id'])) {
                    continue;
                }
                for ($k = 0; $k < min($old['n'], $row['n']); $k++) {
                    $from = Plan::code($old['name'], Plan::num($old, $k));
                    $to = Plan::code($row['name'], Plan::num($row, $k));
                    if ($from !== $to && ! in_array(Plan::num($row, $k), $row['holes'], true)) {
                        $renamed[$from] = $to;
                    }
                }
            }

            $spots = array_merge([], ...array_map(fn (array $row) => array_values(Plan::codes($row)), $rows));
            $old = array_merge([], ...array_map(fn (array $row) => array_values(Plan::codes($row)), $yard->plan()));
            $stored = Vehicle::where('yard_id', $yard->id)->where('state', VehicleState::Stored)->whereNotNull('spot')->with('car.brand')->get();
            foreach ($stored as $v) {
                // Место свободным текстом (не из схемы) схема не трогает.
                if (in_array($v->spot, $old, true) && ! isset($renamed[$v->spot]) && ! in_array($v->spot, $spots, true)) {
                    throw ValidationException::withMessages(['plan' => 'На месте '.$v->spot.' стоит '.($v->brand?->name ?? $v->ref ?? 'ТС').', сначала переставьте её']);
                }
            }

            // Два прохода: A→B и B→A разом упёрлись бы в уникальность места.
            $moving = $stored->filter(fn (Vehicle $v) => isset($renamed[$v->spot]));
            foreach ($moving as $v) {
                Vehicle::whereKey($v->id)->update(['spot' => '#'.$v->id]);
            }
            foreach ($moving as $v) {
                $v->update(['spot' => $renamed[$v->spot]]);
            }

            $yard->update(['rows' => $rows] + ($rows ? ['capacity' => count($spots)] : []));

            return $yard;
        });

        Nav::forgetStaffCounts();
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
            $holes = array_values(array_unique(array_map('intval', is_array($r['holes'] ?? null) ? $r['holes'] : [])));
            $row = [
                'id' => $id, 'name' => $name, 'from' => max(1, min(9999, $from)), 'n' => $n,
                'x' => max(-2000, min(2000, $snap($r['x'] ?? 0))), 'y' => max(-2000, min(2000, $snap($r['y'] ?? 0))),
                'dir' => ($r['dir'] ?? 'h') === 'v' ? 'v' : 'h',
                'w' => max(1.5, min(8, $snap($r['w'] ?? Plan::W))), 'l' => max(2.5, min(25, $snap($r['l'] ?? Plan::L))),
                'rev' => (bool) ($r['rev'] ?? false),
            ];
            $row['holes'] = array_values(array_filter($holes, fn ($h) => $h >= $row['from'] && $h < $row['from'] + $n));
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
