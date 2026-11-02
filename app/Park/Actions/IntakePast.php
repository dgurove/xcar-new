<?php

namespace App\Park\Actions;

use App\Billing\Actions\IssueTransferObligation;
use App\Park\Events\VehicleAccepted;
use App\Park\EventType;
use App\Park\Idle;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Nav;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Принята задним числом — по нашему письму (`AcceptByLetters`): как `Intake`, но без осмотра, места и человека — приём
 * уже случился. Парковки может не быть: тогда в деле ждёт шаг «Нужно указать парковку». Пара к `ReleasePast`.
 */
final class IntakePast
{
    public function __construct(private OpenDocs $openDocs, private IssueTransferObligation $transfer) {}

    public function __invoke(Vehicle $vehicle, ?Yard $yard, Carbon $at, array $mark): bool
    {
        Nav::forgetStaffCounts();
        $accepted = DB::transaction(function () use ($vehicle, $yard, $at, $mark) {
            $locked = Vehicle::whereKey($vehicle->id)->lockForUpdate()->first();
            if (! $locked || $locked->state !== VehicleState::Expected) {
                return false;
            }
            $locked->update([
                'state' => VehicleState::Stored, 'yard_id' => $yard?->id ?? $locked->yard_id, 'spot' => null, 'accepted_at' => $at,
                // Принята задним числом: «стоит долго» о ней уже не новость.
                'idle_noticed_at' => $at->lt(now()->subDays(Idle::warn())) ? now() : null,
            ]);
            $locked->log(EventType::Accepted, null, array_filter(['yard' => $locked->yard?->name, 'yard_id' => $locked->yard_id, 'day' => $at->toDateString()]) + $mark);
            Request::where('vehicle_id', $locked->id)->where('type', RequestType::Intake)->whereIn('state', RequestState::open())
                ->update(['state' => RequestState::Done, 'done_at' => now()]);
            ($this->openDocs)($locked);
            $vehicle->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
        if ($accepted) {
            // Договор комиссии — обязательство перед вендором с приёма, как у `Intake`; человека нет — от первого админа, как
            // у прочих дел системы (`SyncOffer::system`).
            if ($system = User::withRole(Role::Admin)->orderBy('id')->first()) {
                ($this->transfer)($vehicle, $system);
            }
            // Без человека и заявки: администраторам «управляющий принял» не уходит (`Notify::parkHandled`).
            VehicleAccepted::dispatch($vehicle);
        }

        return $accepted;
    }
}
