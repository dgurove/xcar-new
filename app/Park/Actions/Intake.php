<?php

namespace App\Park\Actions;

use App\Billing\Actions\AddCharge;
use App\Billing\Actions\IssueTransferObligation;
use App\Billing\ChargeKind;
use App\Billing\Ledger;
use App\Park\Events\VehicleAccepted;
use App\Park\EventType;
use App\Park\Inspection;
use App\Park\InspectionKind;
use App\Park\Request;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Приём на стоянку одной транзакцией: площадка и место, дата, осмотр записью,
 * копия повреждений и показаний на ТС для списков, закрытие переданной заявки
 * и всех открытых эвакуаций (приезд — конец эвакуации), бумаги вендору,
 * событие для CRM.
 */
final class Intake
{
    public function __construct(private OpenDocs $openDocs, private AddCharge $addCharge, private IssueTransferObligation $transfer) {}

    public function __invoke(Vehicle $vehicle, User $by, Yard $yard, ?Carbon $at, array $inspection = [], ?Request $request = null, ?string $spot = null): Vehicle
    {
        Nav::forgetStaffCounts();
        $vehicle = DB::transaction(function () use ($vehicle, $by, $yard, $at, $inspection, $request, $spot) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if (! $vehicle->state->isBefore()) {
                throw ValidationException::withMessages(['state' => 'ТС уже '.mb_strtolower($vehicle->state->label())]);
            }
            $spot = Vehicle::takeSpot($yard, $spot);
            $at ??= now();
            $zones = array_values($inspection['damage_zones'] ?? []);
            // Дата приёма — первая: после перегона между площадками сутки хранения идут дальше, а не заново.
            $vehicle->update([
                'state' => VehicleState::Stored, 'yard_id' => $yard->id, 'spot' => $spot, 'accepted_at' => $vehicle->accepted_at ?? $at, 'transit_started_at' => null,
                'damage_zones' => $zones, 'damage_note' => ($inspection['damage_note'] ?? null) ?: null,
                'mileage' => $inspection['mileage'] ?? $vehicle->mileage, 'fuel' => $inspection['fuel'] ?? $vehicle->fuel,
            ]);
            Inspection::create(['vehicle_id' => $vehicle->id, 'request_id' => $request?->id, 'kind' => InspectionKind::Intake, 'at' => $at, 'user_id' => $by->id, 'damage_zones' => $zones]
                + self::fields($inspection));
            $vehicle->log(EventType::Accepted, $by, array_filter(['yard' => $yard->name, 'yard_id' => $yard->id, 'spot' => $spot, 'day' => $at->toDateString()]));
            Request::closeOpen($vehicle, [RequestType::Intake, RequestType::Tow], $by, $request);
            ($this->openDocs)($vehicle);
            // Эвакуация со стоимостью — начисление тому, кто платит хранение; договор комиссии — обязательство перед вендором.
            $vehicle->loadMissing('vendor');
            if ($request?->isTow() && $request->cost && ($party = Ledger::payerParty($vehicle, $vehicle->vendor?->storage_payer ?? 'vendor'))) {
                ($this->addCharge)($party, ChargeKind::Tow, 'Эвакуация'.($request->from_address ? ' из '.$request->from_address : '').($request->distance_km ? ', '.$request->distance_km.' км' : ''), 1, 'pc', $request->cost, $by, $vehicle);
            }

            return $vehicle;
        });
        ($this->transfer)($vehicle, $by);
        VehicleAccepted::dispatch($vehicle, $request, $by);

        return $vehicle;
    }

    /** Поля осмотра из формы: пустое не пишется, флаги «требует ремонта» — тройкой да/нет/не смотрели. */
    public static function fields(array $in): array
    {
        $tri = fn ($v) => $v === null || $v === '' ? null : (bool) $v;

        return [
            'mileage' => $in['mileage'] ?? null,
            'fuel' => $in['fuel'] ?? null,
            'keys_count' => $in['keys_count'] ?? null,
            'docs' => array_values($in['docs'] ?? []),
            'equipment' => array_values($in['equipment'] ?? []),
            'repair' => array_map($tri, array_intersect_key($in['repair'] ?? [], Inspection::REPAIR)),
            'damage_note' => ($in['damage_note'] ?? null) ?: null,
            'transit_damage' => ($in['transit_damage'] ?? null) ?: null,
            'missing_parts' => ($in['missing_parts'] ?? null) ?: null,
            'replaced_units' => ($in['replaced_units'] ?? null) ?: null,
            'signer_name' => ($in['signer_name'] ?? null) ?: null,
            'signature_path' => Inspection::storeSignature($in['signature'] ?? null),
        ];
    }
}
