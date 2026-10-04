{{-- Состояние ТС словом и фактами серым текстом: где стоит и сколько, в пути сколько дней, когда выдана или почему не привезена,
     и предупреждения «что не так» (`Park\Alerts` — те же, что тегами в «Наличии», кроме «В документе иначе»: в деле
     это видно у полей).
     only — одна пилюля состояния, без места, дней и предупреждений: в почте рядом с названием машины
     нужен статус, а не всё про неё («номера и парковку я увижу в „Наличии“»). --}}
@props(['vehicle', 'only' => false])
@php $days = $vehicle->daysStored(); @endphp
<x-ui.state :tone="match ($vehicle->state->tone()) { 'open' => 'open', 'urgent' => 'urgent', default => 'closed' }">{{ $vehicle->state->label() }}</x-ui.state>
@if ($only)
@elseif ($vehicle->state === \App\Park\VehicleState::Stored)
    @if ($vehicle->yard)<x-ui.place class="fact">{{ $vehicle->yard->name }}{{ $vehicle->spot ? ', '.$vehicle->spot : '' }}</x-ui.place>@endif
    <span class="fact nums">{{ $days }}&nbsp;д</span>
@elseif ($vehicle->state === \App\Park\VehicleState::InTransit && $vehicle->daysInTransit() !== null)
    <span class="fact nums">{{ $vehicle->daysInTransit() }} дн в пути</span>
@elseif ($vehicle->state === \App\Park\VehicleState::Released)
    <span class="fact nums">{{ $vehicle->released_at?->translatedFormat('j M Y') }}</span>
@elseif ($vehicle->state === \App\Park\VehicleState::Cancelled && $vehicle->cancel_reason)
    <span class="fact">{{ $vehicle->cancel_reason }}</span>
@endif
@unless ($only)
    @foreach (\App\Park\Alerts::of($vehicle, docs: false) as $alert)<x-ui.state :tone="$alert['tone']">{{ $alert['label'] }}</x-ui.state>@endforeach
@endunless
