<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * День забора, о котором договорились с покупателем (02.11.2026, владелец: «надо иметь возможность указывать дату, о которой
 * договорились, и в окошке, и в деле»): `planned_at` открытой заявки на выдачу — её заводит письмо о продаже, нет — заводится
 * тут. Пустая дата — договорённость снята. Пока день не прошёл, ТС не «не забирают» (`Vehicle::pickupLate`).
 */
final class PlanPickup
{
    public function __construct(private CreateRequest $create) {}

    public function __invoke(Vehicle $vehicle, User $by, ?Carbon $at): void
    {
        DB::transaction(function () use ($vehicle, $by, $at) {
            $request = $vehicle->openRequest(RequestType::Release) ?? ($at ? ($this->create)($by, RequestType::Release, $vehicle, []) : null);
            if (! $request || $request->planned_at?->format('Y-m-d H:i') === $at?->format('Y-m-d H:i')) {
                return;
            }
            $request->update(['planned_at' => $at?->copy()->second(0)]);
            $vehicle->log(EventType::PickupPlanned, $by, ['at' => $at?->format('Y-m-d H:i')]);
        });
    }
}
