<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Две ТС одной парковки меняются местами: у обеих в истории перестановка, площадка и дата приёма те же. */
final class SwapSpots
{
    public function __invoke(Vehicle $a, Vehicle $b, User $by): void
    {
        Nav::forgetStaffCounts();

        DB::transaction(function () use ($a, $b, $by) {
            // Замки в том же порядке, что у перестановки (`Move` → `Vehicle::takeSpot`): сначала ТС (по порядку id — встречный
            // обмен тех же двух не ждёт друг друга вечно), потом парковка — перестановка на одно из этих мест ждёт обмена.
            $locked = Vehicle::whereKey([$a->id, $b->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            [$a, $b] = [$locked->get($a->id), $locked->get($b->id)];
            if ($a?->yard_id) {
                Yard::withoutGlobalScopes()->whereKey($a->yard_id)->lockForUpdate()->value('id');
            }
            if (! $a || ! $b || $a->id === $b->id || $a->state !== VehicleState::Stored || $b->state !== VehicleState::Stored || $a->yard_id !== $b->yard_id || ! $a->yard_id) {
                throw ValidationException::withMessages(['spot' => 'Поменять местами можно две ТС одной парковки']);
            }
            // Без места — не обмен: стоящая ТС осталась бы без места. Такую ставят на свободное.
            if (! $a->spot || ! $b->spot) {
                throw ValidationException::withMessages(['spot' => 'Место '.($a->spot ?: $b->spot).' занято, выберите свободное']);
            }
            [$sa, $sb] = [$a->spot, $b->spot];
            $yard = $a->yard->name;
            // Сначала одна освобождает место: иначе обе на миг на одном — уникальность места не пустит.
            Vehicle::whereKey($a->id)->update(['spot' => null]);
            $b->update(['spot' => $sa]);
            $a->update(['spot' => $sb]);
            $a->log(EventType::Moved, $by, ['to' => $yard.', '.$sb, 'yard_id' => $a->yard_id, 'spot' => $sb]);
            $b->log(EventType::Moved, $by, ['to' => $yard.', '.$sa, 'yard_id' => $b->yard_id, 'spot' => $sa]);
        });
    }
}
