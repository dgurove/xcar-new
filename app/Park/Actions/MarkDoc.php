<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Vehicle;
use App\Users\User;
use App\Vendors\DocRequirement;

/** Документ вендору после приёма: отметить отправленным или снять отметку. Возвращает новое состояние. */
final class MarkDoc
{
    public function __invoke(Vehicle $vehicle, DocRequirement $doc, User $by): bool
    {
        $done = $vehicle->docs_done ?? [];
        $was = in_array($doc->value, $done, true);
        $vehicle->update(['docs_done' => array_values($was ? array_diff($done, [$doc->value]) : [...$done, $doc->value])]);
        $vehicle->log($was ? EventType::DocBack : EventType::DocSent, $by, ['doc' => $doc->label()]);

        return ! $was;
    }
}
