{{-- Стоянка в строке заявки — кнопкой (11.11.2026, владелец: «вместо парковки удобная компактная кнопка, чтобы быстро
     указать, и место»; «по указанной тоже пусть открывается окошко»): «📍 Измайловский лес, D-5» или «Указать парковку»
     цветом. Нажатие — общая шторка списка (`place:open`, `RequestController::place`), строку не открывает. Стоит —
     стоянка и место ТС, едет — стоянка приёма у заявки. --}}
@props(['req'])
@php
    $v = $req->vehicle;
    $stored = $v->state === \App\Park\VehicleState::Stored;
    $yard = $stored ? $v->yard : ($req->yard ?? $v->yard);
    $spot = $stored ? $v->spot : null;
@endphp
<button type="button" {{ $attributes->class(['req-place', 'is-empty' => ! $yard]) }} data-controller="emit" data-action="emit#send:stop" data-emit-event-param="place:open" data-emit-url-param="/requests/{{ $req->id }}/place" aria-label="{{ $yard ? 'Парковка и место' : 'Указать парковку' }}">
    <x-ui.place>{{ $yard ? $yard->name.($spot ? ', '.$spot : '') : 'Указать парковку' }}</x-ui.place>
</button>
