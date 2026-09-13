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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Приём на стоянку одной транзакцией: место, дата, повреждения, закрытие заявки на приём. */
final class Intake
{
    public function __invoke(Vehicle $vehicle, User $by, Yard $yard, ?Carbon $at, array $damageZones = [], ?string $damageNote = null): Vehicle
    {
        Nav::forgetStaffCounts();
        return DB::transaction(function () use ($vehicle, $by, $yard, $at, $damageZones, $damageNote) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($vehicle->state !== VehicleState::Expected) {
                throw ValidationException::withMessages(['state' => 'Машина уже '.$vehicle->state->label()]);
            }
            $vehicle->update(['state' => VehicleState::Stored, 'yard_id' => $yard->id, 'accepted_at' => $at ?? now(), 'damage_zones' => array_values($damageZones), 'damage_note' => $damageNote ?: null]);
            $vehicle->log(EventType::Accepted, $by, ['yard' => $yard->name]);
            Request::where('vehicle_id', $vehicle->id)->where('type', RequestType::Intake)->where('state', RequestState::New)
                ->update(['state' => RequestState::Done, 'done_at' => now(), 'assignee_id' => $by->id]);

            return $vehicle;
        });
    }
}
