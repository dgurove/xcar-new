@props(['vehicle'])
@php $tone = $vehicle->state->tone(); @endphp
<span class="chip {{ match($tone) { 'open' => 'bg-open-soft text-open', 'urgent' => 'bg-urgent-soft text-urgent', default => 'bg-closed-soft text-closed' } }}">{{ $vehicle->state->label() }}</span>
@if ($vehicle->state === \App\Park\VehicleState::Stored)
    <span class="chip">{{ $vehicle->yard?->name }}</span>
    <span class="chip tabular-nums">{{ $vehicle->daysStored() }} дн.</span>
@elseif ($vehicle->state === \App\Park\VehicleState::Released)
    <span class="chip tabular-nums">{{ $vehicle->released_at?->translatedFormat('j M Y') }}</span>
@endif
