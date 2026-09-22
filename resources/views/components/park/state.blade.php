{{-- Состояние машины пилюлями: где стоит и сколько (светофор простоя), в пути сколько дней, когда выдана или почему не привезена. --}}
@props(['vehicle'])
@php $days = $vehicle->daysStored(); $idle = \App\Park\Idle::tone($vehicle->state === \App\Park\VehicleState::Stored ? $days : null); @endphp
<x-ui.pill :tone="match ($vehicle->state->tone()) { 'open' => 'open', 'urgent' => 'urgent', default => 'closed' }" class="!min-h-0 !py-1 text-xs">{{ $vehicle->state->label() }}</x-ui.pill>
@if ($vehicle->state === \App\Park\VehicleState::Stored)
    @if ($vehicle->yard)<x-ui.place class="chip">{{ $vehicle->yard->name }}{{ $vehicle->spot ? ', '.$vehicle->spot : '' }}</x-ui.place>@else<x-ui.pill tone="urgent" class="!min-h-0 !py-1 text-xs">Парковка не указана</x-ui.pill>@endif
    <span class="chip nums font-normal {{ $idle === 'danger' ? 'bg-danger-soft text-danger' : ($idle === 'urgent' ? 'bg-urgent-soft text-urgent' : '') }}">{{ $days }} дн</span>
@elseif ($vehicle->state === \App\Park\VehicleState::InTransit && $vehicle->daysInTransit() !== null)
    <span class="chip nums font-normal">{{ $vehicle->daysInTransit() }} дн в пути</span>
@elseif ($vehicle->state === \App\Park\VehicleState::Released)
    <span class="chip">{{ $vehicle->released_at?->translatedFormat('j M Y') }}</span>
@elseif ($vehicle->state === \App\Park\VehicleState::Cancelled && $vehicle->cancel_reason)
    <span class="chip">{{ $vehicle->cancel_reason }}</span>
@elseif ($vehicle->state === \App\Park\VehicleState::Expected && $vehicle->relationLoaded('requests') && ! $vehicle->requests->contains(fn ($r) => $r->isOpen()))
    {{-- Ждёт, а заявки нет (отменили) — иначе про неё забудут: заявка заводится с ТС или из окошка. --}}
    <x-ui.pill tone="urgent" class="!min-h-0 !py-1 text-xs">без заявки</x-ui.pill>
@endif
@if (! $vehicle->state->isFinal() && $vehicle->vinProblem())<x-ui.pill tone="danger" class="!min-h-0 !py-1 text-xs">{{ $vehicle->vinProblem() }}</x-ui.pill>@endif
