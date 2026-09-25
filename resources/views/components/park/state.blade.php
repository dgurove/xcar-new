{{-- Состояние машины пилюлями: где стоит и сколько (светофор простоя), в пути сколько дней, когда выдана или почему не привезена,
     и предупреждения «что не так» (`Park\Alerts` — те же, что тегами в «Наличии»).
     only — одна пилюля состояния, без места, дней и предупреждений: в почте рядом с названием машины
     нужен статус, а не всё про неё («номера и парковку я увижу в „Наличии“»). --}}
@props(['vehicle', 'only' => false])
@php $days = $vehicle->daysStored(); @endphp
<x-ui.pill :tone="match ($vehicle->state->tone()) { 'open' => 'open', 'urgent' => 'urgent', default => 'closed' }" class="!min-h-0 !py-1 text-xs">{{ $vehicle->state->label() }}</x-ui.pill>
@if ($only)
@elseif ($vehicle->state === \App\Park\VehicleState::Stored)
    @if ($vehicle->yard)<x-ui.place class="chip">{{ $vehicle->yard->name }}{{ $vehicle->spot ? ', '.$vehicle->spot : '' }}</x-ui.place>@endif
    <span class="chip nums font-normal">{{ $days }}д</span>
@elseif ($vehicle->state === \App\Park\VehicleState::InTransit && $vehicle->daysInTransit() !== null)
    <span class="chip nums font-normal">{{ $vehicle->daysInTransit() }} дн в пути</span>
@elseif ($vehicle->state === \App\Park\VehicleState::Released)
    <span class="chip">{{ $vehicle->released_at?->translatedFormat('j M Y') }}</span>
@elseif ($vehicle->state === \App\Park\VehicleState::Cancelled && $vehicle->cancel_reason)
    <span class="chip">{{ $vehicle->cancel_reason }}</span>
@endif
@unless ($only)
    @foreach (\App\Park\Alerts::of($vehicle) as $alert)<x-ui.pill :tone="$alert['tone']" class="!min-h-0 !py-1 text-xs">{{ $alert['label'] }}</x-ui.pill>@endforeach
@endunless
