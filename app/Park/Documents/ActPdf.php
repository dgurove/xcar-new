<?php

namespace App\Park\Documents;

use App\Park\InspectionKind;
use App\Park\Vehicle;
use App\Support\Pdf;

/** Акт приёма или выдачи — тот же `park.act`, что печатают со страницы, только файлом: в письмо вендору. */
final class ActPdf
{
    public function render(Vehicle $vehicle, bool $intake = true): string
    {
        $vehicle->loadMissing(['brand', 'model', 'vendor', 'yard.settlement', 'media', 'inspections']);

        return Pdf::render('park.act', [
            'vehicle' => $vehicle, 'intake' => $intake, 'company' => config('xcar.company', []),
            'inspection' => $vehicle->lastInspection($intake ? InspectionKind::Intake : InspectionKind::Release),
        ], chroot: [storage_path('app/media')]);
    }

    public function filename(Vehicle $vehicle, bool $intake = true): string
    {
        return ($intake ? 'akt-priema' : 'akt-vydachi').($vehicle->ref ? '-'.preg_replace('/[^\w-]+/u', '-', $vehicle->ref) : '').'.pdf';
    }
}
