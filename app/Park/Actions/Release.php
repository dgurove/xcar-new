<?php

namespace App\Park\Actions;

use App\Billing\Ledger;
use App\Park\Events\VehicleReleased;
use App\Park\EventType;
use App\Park\Inspection;
use App\Park\InspectionKind;
use App\Park\ReleasedTo;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Money;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Выдача — зеркало приёма: осмотр при выдаче, кому выдана, дата не раньше постановки, заявка закрывается. */
final class Release
{
    public function __invoke(Vehicle $vehicle, User $by, ?Carbon $at, ?string $note = null, ?ReleasedTo $to = null, array $inspection = [], ?Request $request = null, bool $force = false): Vehicle
    {
        Nav::forgetStaffCounts();
        $vehicle = DB::transaction(function () use ($vehicle, $by, $at, $note, $to, $inspection, $request, $force) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($vehicle->state !== VehicleState::Stored) {
                throw ValidationException::withMessages(['state' => 'Выдать можно только ТС на стоянке']);
            }
            // Долг по ТС держит выдачу, если у вендора не разрешено выдавать без оплаты; обход — с подтверждением, и это остаётся в ленте.
            $debt = Ledger::vehicleDebt($vehicle);
            $vehicle->loadMissing('vendor');
            if ($debt > 0 && ! $force && ! ($vehicle->vendor?->release_without_payment ?? false)) {
                throw ValidationException::withMessages(['state' => 'Не оплачено '.Money::rub($debt).' — выдача только после оплаты или с подтверждением']);
            }
            $at ??= now();
            if ($vehicle->accepted_at && $at->lt($vehicle->accepted_at)) {
                throw ValidationException::withMessages(['released_at' => 'Выдача раньше приёма']);
            }
            $vehicle->update(['state' => VehicleState::Released, 'released_at' => $at, 'spot' => null]);
            if ($inspection) {
                Inspection::create(['vehicle_id' => $vehicle->id, 'request_id' => $request?->id, 'kind' => InspectionKind::Release, 'at' => $at, 'user_id' => $by->id,
                    'damage_zones' => array_values($inspection['damage_zones'] ?? [])] + Intake::fields($inspection));
            }
            $vehicle->log(EventType::Released, $by, array_filter(['note' => $note, 'to' => $to?->label(), 'unpaid' => $debt > 0 ? $debt : null]));
            $done = ['state' => RequestState::Done, 'done_at' => now(), 'done_by' => $by->id, 'note' => $note];
            if ($request) {
                $request->update($done);
            }
            Request::where('vehicle_id', $vehicle->id)->where('type', RequestType::Release)->whereIn('state', RequestState::open())->update($done);

            return $vehicle;
        });
        VehicleReleased::dispatch($vehicle, $request, $by);

        return $vehicle;
    }
}
