<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleEvent;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Парковка у стоящей ТС, заведённой без неё (по письмам или по факту): ТС стояла там с самого приёма, поэтому
 * правится событие приёма, а не пишется перестановка (`Move` режет `yardTimeline` со дня перестановки).
 */
final class SetYard
{
    public function __invoke(Vehicle $vehicle, User $by, Yard $yard, ?string $spot = null): Vehicle
    {
        Nav::forgetStaffCounts();

        return DB::transaction(function () use ($vehicle, $by, $yard, $spot) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($vehicle->state !== VehicleState::Stored || $vehicle->yard_id) {
                throw ValidationException::withMessages(['yard_id' => 'Парковка уже указана']);
            }
            $spot = Vehicle::takeSpot($yard, $spot, $vehicle->id);
            $vehicle->update(['yard_id' => $yard->id, 'spot' => $spot]);
            /** @var ?VehicleEvent $accepted */
            $accepted = $vehicle->events()->where('type', EventType::Accepted)->orderBy('id')->first();
            if ($accepted) {
                $accepted->update(['payload' => array_filter(['yard' => $yard->name, 'yard_id' => $yard->id, 'spot' => $spot] + ($accepted->payload ?? []))]);
            }
            $vehicle->log(EventType::Updated, $by, ['fields' => array_filter(['yard_id' => $yard->name, 'spot' => $spot])]);

            return $vehicle;
        });
    }
}
