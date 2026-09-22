<?php

namespace App\Park\Actions;

use App\Billing\Actions\SettleStorage;
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
    public function __construct(private SettleStorage $settle, private PurgeLetters $purge) {}

    /** `cash` — хранение по день выдачи выставляется тут же, счёт покупателю гасится наличными; долга не остаётся. */
    public function __invoke(Vehicle $vehicle, User $by, ?Carbon $at, ?string $note = null, ?ReleasedTo $to = null, array $inspection = [], ?Request $request = null, bool $force = false, bool $cash = false): Vehicle
    {
        Nav::forgetStaffCounts();
        $vehicle = DB::transaction(function () use ($vehicle, $by, $at, $note, $to, $inspection, $request, $force, $cash) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($vehicle->state !== VehicleState::Stored) {
                throw ValidationException::withMessages(['state' => 'Выдать можно только ТС на парковке']);
            }
            $at ??= now();
            if ($cash) {
                ($this->settle)($vehicle, $by, $at, cash: true);
                $vehicle->refresh();
            }
            // Долг по ТС — неоплаченные счета и то, что ещё не выставлено (хранение по день выдачи, начисления), — держит
            // выдачу, если у вендора не разрешено выдавать без оплаты; обход — с подтверждением, и это остаётся в ленте.
            $debt = Ledger::vehicleDebt($vehicle);
            $unbilled = Ledger::vehicleUnbilled($vehicle, $at);
            $vehicle->loadMissing('vendor');
            if ($debt + $unbilled > 0 && ! $force && ! ($vehicle->vendor?->release_without_payment ?? false)) {
                throw ValidationException::withMessages(['state' => implode(', ', array_filter([$debt > 0 ? 'не оплачено '.Money::rub($debt) : null, $unbilled > 0 ? 'не выставлено '.Money::rub($unbilled) : null])).' — выдача после оплаты или с подтверждением']);
            }
            if ($vehicle->accepted_at && $at->lt($vehicle->accepted_at)) {
                throw ValidationException::withMessages(['released_at' => 'Выдача раньше приёма']);
            }
            $vehicle->update(['state' => VehicleState::Released, 'released_at' => $at, 'spot' => null]);
            if ($inspection) {
                Inspection::create(['vehicle_id' => $vehicle->id, 'request_id' => $request?->id, 'kind' => InspectionKind::Release, 'at' => $at, 'user_id' => $by->id,
                    'damage_zones' => array_values($inspection['damage_zones'] ?? [])] + Intake::fields($inspection));
            }
            $vehicle->log(EventType::Released, $by, array_filter(['note' => $note, 'to' => $to?->label(), 'unpaid' => $debt + $unbilled > 0 ? round($debt + $unbilled, 2) : null]));
            Request::closeOpen($vehicle, [RequestType::Release], $by, $request, $note);
            // Осмотры, перестановки, перегоны выданной ТС — уже не дела.
            Request::where('vehicle_id', $vehicle->id)->whereIn('state', RequestState::open())
                ->update(['state' => RequestState::Cancelled, 'done_at' => now(), 'done_by' => $by->id, 'cancel_reason' => 'ТС выдана']);

            return $vehicle;
        });
        VehicleReleased::dispatch($vehicle, $request, $by);
        // Из писем больше ничего не хранится: кадры и документы из писем стёрты, письма веток заморожены.
        ($this->purge)($vehicle);

        return $vehicle;
    }
}
