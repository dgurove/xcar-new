<?php

namespace App\Park\Actions;

use App\Support\Nav;
use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Перестановка: меняется площадка, не состояние и не дата приёма. */
final class Move
{
    public function __invoke(Vehicle $vehicle, User $by, Yard $to): Vehicle
    {
        Nav::forgetStaffCounts();
        return DB::transaction(function () use ($vehicle, $by, $to) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($vehicle->state !== VehicleState::Stored) {
                throw ValidationException::withMessages(['state' => 'Переставить можно только ТС на стоянке']);
            }
            $from = $vehicle->yard?->name;
            $vehicle->update(['yard_id' => $to->id]);
            $vehicle->log(EventType::Moved, $by, ['from' => $from, 'to' => $to->name]);
            Request::where('vehicle_id', $vehicle->id)->where('type', RequestType::Move)->where('state', RequestState::New)
                ->update(['state' => RequestState::Done, 'done_at' => now(), 'assignee_id' => $by->id]);

            return $vehicle;
        });
    }
}
