<?php

namespace App\Park\Actions;

use App\Park\Events\VehicleDeparted;
use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Эвакуатор погрузил: заявка в работе, ТС в пути; при перегоне между площадками прежняя площадка освобождается. */
final class StartTow
{
    public function __invoke(Request $request, User $by): Request
    {
        if ($request->type !== RequestType::Tow || ! $request->isOpen()) {
            throw ValidationException::withMessages(['state' => 'Заявка не на эвакуацию или уже закрыта']);
        }
        Nav::forgetStaffCounts();
        $request = DB::transaction(function () use ($request, $by) {
            $vehicle = Vehicle::whereKey($request->vehicle_id)->lockForUpdate()->firstOrFail();
            if ($vehicle->state->isFinal() || $vehicle->state === VehicleState::InTransit) {
                throw ValidationException::withMessages(['state' => 'ТС уже '.mb_strtolower($vehicle->state->label())]);
            }
            $from = $vehicle->state === VehicleState::Stored ? $vehicle->yard?->name : null;
            $vehicle->update(['state' => VehicleState::InTransit, 'transit_started_at' => now(), 'yard_id' => null, 'spot' => null]);
            $request->update(['state' => RequestState::InProgress, 'started_at' => now()]);
            $vehicle->log(EventType::Departed, $by, array_filter(['carrier' => $request->carrier, 'from' => $from]));

            return $request->setRelation('vehicle', $vehicle);
        });
        VehicleDeparted::dispatch($request->vehicle, $request, $by);

        return $request;
    }
}
