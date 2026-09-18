<?php

namespace App\Park\Actions;

use App\Park\Events\VehicleCancelled;
use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** ТС не привезут: вендор отозвал, клиент не отдал, продали на месте. Все открытые заявки отменяются. */
final class CancelVehicle
{
    public function __invoke(Vehicle $vehicle, User $by, ?string $reason = null): Vehicle
    {
        Nav::forgetStaffCounts();
        $vehicle = DB::transaction(function () use ($vehicle, $by, $reason) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if (! $vehicle->state->isBefore()) {
                throw ValidationException::withMessages(['state' => 'Отменить можно только ожидаемую или едущую ТС']);
            }
            $vehicle->update(['state' => VehicleState::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason ?: null, 'transit_started_at' => null]);
            Request::where('vehicle_id', $vehicle->id)->whereIn('state', RequestState::open())
                ->update(['state' => RequestState::Cancelled, 'done_at' => now(), 'done_by' => $by->id, 'cancel_reason' => $reason ?: 'ТС не привезена']);
            $vehicle->log(EventType::Cancelled, $by, array_filter(['reason' => $reason]));

            return $vehicle;
        });
        VehicleCancelled::dispatch($vehicle, null, $by);

        return $vehicle;
    }
}
