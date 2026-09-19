<?php

namespace App\Http\Park;

use App\Billing\Party;
use App\Park\InspectionKind;
use App\Park\Vehicle;

/** Акты приёма и выдачи — страницей на печать: их печатают и подписывают руками. */
class ActController
{
    public function show(Vehicle $vehicle, string $kind)
    {
        $vehicle->load(['brand', 'model', 'vendor.party', 'ownerParty', 'yard.settlement', 'media', 'inspections']);
        if (in_array($kind, ['contract', 'handover'], true)) {
            return view('billing.docs.commission', ['vehicle' => $vehicle, 'kind' => $kind, 'self' => Party::self()]);
        }
        abort_if($kind === 'release' && ! $vehicle->released_at && ! $vehicle->lastInspection(InspectionKind::Release), 404);
        abort_if($kind === 'intake' && ! $vehicle->accepted_at, 404);

        $inspection = $vehicle->lastInspection($kind === 'intake' ? InspectionKind::Intake : InspectionKind::Release);

        return view('park.act', ['vehicle' => $vehicle, 'intake' => $kind === 'intake', 'inspection' => $inspection, 'company' => config('xcar.company', [])]);
    }
}
