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
            // Переведена из другого города без парковки (`Transfer`) — парковка с перевода, приём остаётся своим.
            /** @var ?VehicleEvent $accepted */
            $accepted = $vehicle->events()->where('type', EventType::Moved)->whereRaw("(payload->>'transfer')::bool")->whereRaw("payload->>'yard_id' is null")->latest('id')->first()
                ?? $vehicle->events()->where('type', EventType::Accepted)->orderBy('id')->first();
            if ($accepted) {
                $accepted->update(['payload' => array_filter(['yard_id' => $yard->id, 'spot' => $spot] + ($accepted->type === EventType::Accepted ? ['yard' => $yard->name] : ['to' => $yard->name]) + ($accepted->payload ?? []))]);
            }
            $vehicle->log(EventType::Updated, $by, ['fields' => $spot ? ['yard_id', 'spot'] : ['yard_id'], 'changes' => array_filter(['yard_id' => [null, $yard->id], 'spot' => $spot ? [null, $spot] : null])]);

            return $vehicle;
        });
    }
}
