<?php

namespace App\Logistics\Actions;

use App\Logistics\Depot;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Route;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Другая стоянка на месте этой (20.10.2026, как в Google Картах — нажал на точку, выбрал другую): «куда» отрезка перед ней
 * и «откуда» отрезка за ней; исполнители и цены остаются на отрезках. Пока машину до неё не довезли.
 */
final class ReplaceStop
{
    public function __invoke(Transport $before, Depot $depot, User $by): Transport
    {
        $after = Route::after($before);
        if (! ($before->hasLogist($by) || $by->leadsLogistics())) {
            throw ValidationException::withMessages(['depot_id' => 'Стоянки в маршруте ставит логист']);
        }
        if (! $after || ! Route::movable($before, $after)) {
            throw ValidationException::withMessages(['depot_id' => 'ТС уже на стоянке: менять поздно']);
        }
        if ($depot->logist_id !== $before->logist_id || $depot->archived_at) {
            throw ValidationException::withMessages(['depot_id' => 'Какая стоянка']);
        }
        if ($before->depot_id === $depot->id) {
            return $before;
        }
        $was = $before->depot?->label();
        DB::transaction(function () use ($before, $after, $depot, $by, $was) {
            Route::setStop($before, $after, $depot);
            TransportEvent::record($before, 'stop', $by, ['depot' => $depot->label().($was ? ', была '.$was : '')]);
        });
        if ($before->isOpen()) {
            TransportMoved::dispatch($before->fresh(), $by, 'route');
        }

        return $before;
    }
}
