<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Выдача — зеркало приёма: дата не раньше постановки, заявка на выдачу закрывается. */
final class Release
{
    public function __invoke(Vehicle $vehicle, User $by, ?Carbon $at, ?string $note = null): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $by, $at, $note) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($vehicle->state !== VehicleState::Stored) {
                throw ValidationException::withMessages(['state' => 'Выдать можно только машину на стоянке']);
            }
            $at ??= now();
            if ($vehicle->accepted_at && $at->lt($vehicle->accepted_at)) {
                throw ValidationException::withMessages(['released_at' => 'Выдача раньше приёма']);
            }
            $vehicle->update(['state' => VehicleState::Released, 'released_at' => $at]);
            $vehicle->log(EventType::Released, $by, array_filter(['note' => $note]));
            Request::where('vehicle_id', $vehicle->id)->where('type', RequestType::Release)->where('state', RequestState::New)
                ->update(['state' => RequestState::Done, 'done_at' => now(), 'assignee_id' => $by->id]);

            return $vehicle;
        });
    }
}
