<?php

namespace App\Logistics\Actions;

use App\Logistics\Depot;
use App\Logistics\Route;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Users\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Стоянки в другом порядке (20.10.2026, перетаскиванием за «≡», как остановки в Google Картах): меняются точки, а отрезки
 * остаются на своих местах вместе с исполнителями и ценами. Двигаются только стоянки, до которых машину не довезли; прочие
 * стоят где стояли. Возвращает отрезки, у которых сменились точки (их исполнителям — «куда» поменялось).
 *
 * @return Collection<int, Transport>
 */
final class ReorderStops
{
    /** @param list<int> $order id стоянок маршрута в новом порядке */
    public function __invoke(Transport $t, array $order, User $by): Collection
    {
        if (! ($t->hasLogist($by) || $by->leadsLogistics())) {
            throw ValidationException::withMessages(['order' => 'Стоянки в маршруте ставит логист']);
        }
        $legs = Route::legs($t)->values();
        $stops = $legs->slice(0, -1)->map(fn (Transport $leg) => (int) $leg->depot_id)->values()->all();
        $order = array_map('intval', $order);
        if (count($order) !== count($stops) || array_diff($order, $stops) || array_diff($stops, $order)) {
            throw ValidationException::withMessages(['order' => 'Маршрут поменялся, обновите страницу']);
        }
        foreach ($stops as $k => $depot) {
            if ($order[$k] !== $depot && ! Route::movable($legs[$k], $legs[$k + 1])) {
                throw ValidationException::withMessages(['order' => 'Машина уже на стоянке: её не переставить']);
            }
        }
        $changed = collect();
        DB::transaction(function () use ($legs, $stops, $order, $by, $changed) {
            $depots = Depot::whereKey($order)->get()->keyBy('id');
            foreach ($order as $k => $id) {
                if ($id === $stops[$k]) {
                    continue;
                }
                Route::setStop($legs[$k], $legs[$k + 1], $depots[$id]);
                $changed->push($legs[$k], $legs[$k + 1]);
            }
            if ($changed->isNotEmpty()) {
                TransportEvent::record($legs[0], 'stops', $by, ['order' => implode(' → ', array_map(fn ($id) => $depots[$id]->name, $order))]);
            }
        });

        return $changed->unique('id')->values();
    }
}
