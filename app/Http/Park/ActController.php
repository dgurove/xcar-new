<?php

namespace App\Http\Park;

use App\Park\InspectionKind;
use App\Park\Vehicle;

/** Акты приёма и выдачи — страницей на печать: их печатают и подписывают руками. */
class ActController
{
    public function show(Vehicle $vehicle, string $kind)
    {
        $vehicle->load(['brand', 'model', 'vendor', 'yard.settlement', 'media', 'inspections']);
        abort_if($kind === 'release' && ! $vehicle->released_at, 404);
        abort_if($kind === 'intake' && ! $vehicle->accepted_at, 404);

        $inspection = $vehicle->lastInspection($kind === 'intake' ? InspectionKind::Intake : InspectionKind::Release);

        return view('park.act', ['vehicle' => $vehicle, 'intake' => $kind === 'intake', 'inspection' => $inspection, 'company' => config('xcar.company', [])]);
    }
}
