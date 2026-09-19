<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Inspection;
use App\Park\InspectionKind;
use App\Park\Request;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Получатель осмотрел ТС, подписал «не соответствует» и не забрал: осмотр выдачи с отказом остаётся актом
 * для страховой, ТС — на стоянке, заявка на выдачу — открытой. Дальше — письмо вендору с этим актом.
 */
final class RefuseRelease
{
    public function __invoke(Vehicle $vehicle, User $by, ?Carbon $at, string $note, array $inspection = [], ?Request $request = null): Inspection
    {
        if ($vehicle->state !== VehicleState::Stored) {
            throw ValidationException::withMessages(['state' => 'ТС не на стоянке']);
        }
        Nav::forgetStaffCounts();

        return DB::transaction(function () use ($vehicle, $by, $at, $note, $inspection, $request) {
            $insp = Inspection::create(['vehicle_id' => $vehicle->id, 'request_id' => $request?->id, 'kind' => InspectionKind::Release, 'at' => $at ?? now(), 'user_id' => $by->id,
                'damage_zones' => array_values($inspection['damage_zones'] ?? []), 'matches' => false, 'mismatch_note' => $note, 'refused' => true] + Intake::fields($inspection));
            $vehicle->log(EventType::ReleaseRefused, $by, ['note' => $note]);
            $request?->update(['note' => trim(($request->note ? $request->note."\n" : '').'Отказался получать: '.$note)]);

            return $insp;
        });
    }
}
