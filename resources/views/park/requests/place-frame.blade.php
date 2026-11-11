{{-- Шторка стоянки из строки заявки (`RequestController::place`, общая на список — `park/requests/index`): то же, что
     «Переставить» в карточке (`park/vehicles/move-sheet`) — парковка, место подсказкой свободных и схемой. Стоит без
     стоянки — `SetYard` (правит приём); ещё едет — только стоянка приёма у заявки, мест нет. Отправка — в рамке
     (`RequestController::savePlace`): ошибка остаётся в шторке. --}}
@php
    $button = $stored && $vehicle->yard_id ? 'Переставить' : 'Сохранить';
@endphp
<turbo-frame id="place-sheet">
    <form method="post" action="/requests/{{ $req->id }}/place" class="flex flex-col gap-3">
        @csrf
        <p class="text-sm text-ink-muted">{{ $vehicle->titleWithYear() }}@if ($vehicle->plate), {{ $vehicle->plate }}@endif</p>
        @if ($stored)
            <div class="flex flex-col gap-3" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
                <div class="fields">
                    <x-park.yard-field :yards="$yards" :value="$vehicle->yard_id" required placeholder="Выберите" data-spots-target="yard" data-action="change->spots#sync"/>
                    <x-ui.field name="spot" label="Место" :value="$vehicle->spot" list="place-spots-{{ $vehicle->id }}" autocapitalize="characters"/>
                    <datalist id="place-spots-{{ $vehicle->id }}" data-spots-target="list"></datalist>
                </div>
                @include('park.yards.plan-pick-frame', ['pick' => $vehicle->id, 'at' => $vehicle->spot])
            </div>
        @else
            <div class="fields">
                <x-park.yard-field :yards="$yards" label="Парковка приёма" :value="$req->yard_id" required placeholder="Выберите"/>
            </div>
        @endif
        <x-ui.sheet-foot>{{ $button }}</x-ui.sheet-foot>
    </form>
</turbo-frame>
