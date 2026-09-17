{{-- Состояние машины пилюлями: где стоит и сколько, либо когда выдана. --}}
@props(['vehicle'])
<x-ui.pill :tone="match ($vehicle->state->tone()) { 'open' => 'open', 'urgent' => 'urgent', default => 'closed' }" class="!min-h-0 !py-1 text-xs">{{ $vehicle->state->label() }}</x-ui.pill>
@if ($vehicle->state === \App\Park\VehicleState::Stored)
    <x-ui.place class="chip">{{ $vehicle->yard?->name }}</x-ui.place>
    <span class="chip nums font-normal">{{ $vehicle->daysStored() }} дн.</span>
@elseif ($vehicle->state === \App\Park\VehicleState::Released)
    <span class="chip">{{ $vehicle->released_at?->translatedFormat('j M Y') }}</span>
@endif
