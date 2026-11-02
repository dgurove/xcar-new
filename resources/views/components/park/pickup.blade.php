{{-- День забора, о котором договорились с покупателем (02.11.2026, владелец: «указывать дату, о которой договорились, и в
     окошке, и в деле»): кнопка-дата с календарём и временем (`x-ui.date chip submit`), своя форма рядом (`POST /cars/{v}/pickup`,
     `PlanPickup`); пустая — «назначить день забора», «Очистить» снимает договорённость. Править — `parkDates`, остальным текст.
     `key` — второй на той же странице свой id. --}}
@props(['vehicle', 'key' => 'at', 'placeholder' => 'назначить день забора'])
@php $at = $vehicle->pickupAt(); $form = 'pickup-'.$vehicle->id.'-'.$key; @endphp
@if (auth()->user()?->parkDates())
    <x-ui.date chip submit time name="pickup_at" :id="'pk-'.$vehicle->id.'-'.$key" :value="$at" :placeholder="$placeholder" :form="$form" {{ $attributes->only('class') }}/>
    <form method="post" action="/cars/{{ $vehicle->id }}/pickup" id="{{ $form }}" hidden>@csrf</form>
@elseif ($at)
    <span {{ $attributes->only('class') }}>{{ \App\Support\Ago::day($at, true) }}</span>
@endif
