{{-- Где стоит ТС — сама кнопка перестановки (владелец 10.10.2026: «сделай перестановку понятнее»): «Измайловский лес, A-3 ⌄»,
     нажатие — шторка «Переставить» (`park/vehicles/move-sheet`, событие move:open). Форма — классом снаружи (tag, fact); chevron=false — во фразе положения карточки, там вместо стрелки пунктир. --}}
@props(['vehicle', 'chevron' => true])
<button type="button" {{ $attributes->merge(['class' => 'inline-flex items-center gap-1']) }} data-controller="emit" data-action="emit#send" data-emit-event-param="move:open" aria-label="Переставить"><x-ui.place>{{ $vehicle->yard->name }}{{ $vehicle->spot ? ', '.$vehicle->spot : '' }}</x-ui.place>@if ($chevron)<x-ui.icon name="chevron-down" class="size-3.5 opacity-60"/>@endif</button>
