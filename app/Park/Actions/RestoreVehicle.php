<?php

namespace App\Park\Actions;

use App\Park\Events\VehicleRestored;
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

/** «Снова ждём»: отменённая по ошибке (или передумали) ТС возвращается в ожидание с новой заявкой на приём. */
final class RestoreVehicle
{
    public function __construct(private CreateRequest $create) {}

    public function __invoke(Vehicle $vehicle, User $by, ?string $reason = null): Vehicle
    {
        Nav::forgetStaffCounts();

        $vehicle = DB::transaction(function () use ($vehicle, $by, $reason) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($vehicle->state !== VehicleState::Cancelled) {
                throw ValidationException::withMessages(['state' => 'Вернуть в ожидание можно только ТС «не привезена»']);
            }
            $vehicle->update(['state' => VehicleState::Expected, 'cancelled_at' => null, 'cancel_reason' => null]);
            $vehicle->log(EventType::Restored, $by, array_filter(['reason' => $reason]));
            if (! Request::where('vehicle_id', $vehicle->id)->whereIn('type', [RequestType::Intake, RequestType::Tow])->whereIn('state', RequestState::open())->exists()) {
                ($this->create)($by, RequestType::Intake, $vehicle, ['contact_name' => $vehicle->contact_name, 'contact_phone' => $vehicle->contact_phone]);
            }

            return $vehicle;
        });
        VehicleRestored::dispatch($vehicle, $by);

        return $vehicle;
    }
}
