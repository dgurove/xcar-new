{{-- Состояние ТС словом и фактами серым текстом: где стоит и сколько, в пути сколько дней, когда выдана или почему не привезена,
     и предупреждения «что не так» (`Park\Alerts` — те же, что тегами в «Наличии», кроме «В документе иначе»: в деле
     это видно у полей).
     only — одна пилюля состояния, без места, дней и предупреждений: в почте рядом с названием машины
     нужен статус, а не всё про неё («номера и парковку я увижу в „Наличии“»).
     move — место стоящей ТС кнопкой шторки «Переставить» (шапка дела; шторка — `park/vehicles/move-sheet`) и ставка шторкой. --}}
@props(['vehicle', 'only' => false, 'move' => false])
@php $days = $vehicle->daysStored(); @endphp
<x-ui.state :tone="match ($vehicle->state->tone()) { 'open' => 'open', 'urgent' => 'urgent', default => 'closed' }">{{ $vehicle->state->label() }}</x-ui.state>
@if ($only)
@elseif ($vehicle->state === \App\Park\VehicleState::Stored)
    @if ($vehicle->yard && $move)<x-park.place-move :vehicle="$vehicle" class="fact"/>
    @elseif ($vehicle->yard)<x-ui.place class="fact">{{ $vehicle->yard->name }}{{ $vehicle->spot ? ', '.$vehicle->spot : '' }}</x-ui.place>@endif
    <span class="fact nums">{{ $days }}&nbsp;д</span>
    {{-- В шапке дела — и ставка шторкой (12.10.2026, `x-park.rate`): одно место, где её ставят. --}}
    @if ($move)<x-park.rate :vehicle="$vehicle" key="head" class="fact"/>@endif
@elseif ($vehicle->state === \App\Park\VehicleState::Released)
    <span class="fact nums">{{ $vehicle->released_at?->translatedFormat('j M Y') }}</span>
@elseif ($vehicle->state === \App\Park\VehicleState::Cancelled && $vehicle->cancel_reason)
    <span class="fact">{{ $vehicle->cancel_reason }}</span>
@endif
@unless ($only)
    {{-- «Город не определён» в деле — строкой с кнопкой ниже (`x-park.city-pick`), тегом здесь не повторяется. --}}
    @foreach (collect(\App\Park\Alerts::of($vehicle, docs: false))->reject(fn ($a) => $a['label'] === 'Город не определён') as $alert)<x-ui.state :tone="$alert['tone']">{{ $alert['label'] }}</x-ui.state>@endforeach
@endunless
