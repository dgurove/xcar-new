<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Перестановка: меняется площадка и место, не состояние и не дата приёма. */
final class Move
{
    public function __invoke(Vehicle $vehicle, User $by, Yard $to, ?string $spot = null, ?Request $request = null): Vehicle
    {
        Nav::forgetStaffCounts();

        return DB::transaction(function () use ($vehicle, $by, $to, $spot, $request) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($vehicle->state !== VehicleState::Stored) {
                throw ValidationException::withMessages(['state' => 'Переставить можно только ТС на стоянке']);
            }
            $spot = Vehicle::takeSpot($to, $spot, $vehicle->id);
            $from = $vehicle->yard?->name;
            $sameYard = $vehicle->yard_id === $to->id;
            $vehicle->update(['yard_id' => $to->id, 'spot' => $spot]);
            $vehicle->log(EventType::Moved, $by, ($sameYard ? ['to' => $to->name.($spot ? ', '.$spot : '')] : ['from' => $from, 'to' => $to->name.($spot ? ', '.$spot : '')]) + ['yard_id' => $to->id]);
            Request::closeOpen($vehicle, [RequestType::Move], $by, $request);

            return $vehicle;
        });
    }
}
