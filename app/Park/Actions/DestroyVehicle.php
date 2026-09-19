<?php

namespace App\Park\Actions;

use App\Mail\Thread;
use App\Park\EventType;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Support\Nav;
use Illuminate\Validation\ValidationException;

/** Удалить ТС можно только без следов: ни событий кроме «заведена», ни фото, ни писем, ни оффера — иначе отменить. */
final class DestroyVehicle
{
    public function __invoke(Vehicle $vehicle): void
    {
        $traces = $vehicle->events()->where('type', '!=', EventType::Created)->exists()
            || $vehicle->media()->exists() || $vehicle->offer_id
            || Thread::where('vehicle_id', $vehicle->id)->exists()
            || $vehicle->requests()->where('state', '!=', RequestState::New)->exists()
            || $vehicle->docs()->exists() || $vehicle->charges()->exists() || $vehicle->invoices()->exists();
        if ($traces) {
            throw ValidationException::withMessages(['vehicle' => 'У ТС есть следы — отмените вместо удаления']);
        }
        Nav::forgetStaffCounts();
        $vehicle->delete();
    }
}
