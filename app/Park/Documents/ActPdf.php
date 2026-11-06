<?php

namespace App\Park\Documents;

use App\Billing\Seller;
use App\Park\InspectionKind;
use App\Park\Vehicle;
use App\Support\Pdf;

/** Акт приёма или выдачи — тот же `park.act`, что печатают со страницы, только файлом: в письмо вендору. */
final class ActPdf
{
    public function render(Vehicle $vehicle, bool $intake = true): string
    {
        $vehicle->loadMissing(['vendor', 'yard.settlement', 'media', 'inspections']);

        return Pdf::render('park.act', [
            'vehicle' => $vehicle, 'intake' => $intake, 'self' => Seller::ofVendor($vehicle->vendor)->party(),
            'inspection' => $vehicle->lastInspection($intake ? InspectionKind::Intake : InspectionKind::Release),
        ], chroot: [storage_path('app/media')]);
    }

    public function filename(Vehicle $vehicle, bool $intake = true): string
    {
        // «Акт выдачи Z691-046-06548-26, Kia Rio, 2019, VIN …, А123ВС77.pdf» (06.11.2026, Сергей; `Vehicle::docLine`).
        return \App\Media\PhotosPdf::fileName($intake ? 'Акт приёма' : 'Акт выдачи', $vehicle->docLine());
    }
}
