<?php

namespace App\Park\Actions;

use App\Offers\Flag;
use App\Park\Doc;
use App\Park\DocKind;
use App\Park\Vehicle;
use App\Vendors\DocRequirement;

/**
 * Какие бумаги открыть при приёме: что вендор просит (правило вендора или
 * своё у машины), по умолчанию — акт п/п, акт хранения, фото. Лизинг ждёт от
 * нас ещё и соглашения; ключи и СТС — ждём от вендора, если их не было.
 */
final class OpenDocs
{
    public function __invoke(Vehicle $vehicle): void
    {
        $vehicle->loadMissing('vendor');
        $required = $vehicle->docsRequired();
        $out = array_values(array_unique(array_filter(array_map(fn (DocRequirement $r) => DocKind::fromRequirement($r), $required)), SORT_REGULAR));
        if (! $out) {
            $out = [DocKind::HandoverAct, DocKind::StorageAct, DocKind::Photos];
        }
        $flags = $vehicle->flagList();
        if (in_array(Flag::Leasing, $flags, true) && ! in_array(DocKind::Agreement, $out, true)) {
            $out[] = DocKind::Agreement;
        }
        $notes = array_filter([
            in_array(DocRequirement::TwoCopies, $required, true) ? 'в двух экземплярах' : null,
            in_array(DocRequirement::ColorScan, $required, true) ? 'цветной скан' : null,
            in_array(DocRequirement::SignedScan, $required, true) ? 'скан подписанного' : null,
        ]);
        foreach ($out as $kind) {
            Doc::firstOrCreate(['vehicle_id' => $vehicle->id, 'kind' => $kind->value, 'direction' => 'out'], ['note' => $notes ? implode(', ', $notes) : null]);
        }
        if (in_array(Flag::KeysPending, $flags, true)) {
            Doc::firstOrCreate(['vehicle_id' => $vehicle->id, 'kind' => DocKind::KeysDocs->value, 'direction' => 'in']);
        }
    }
}
