{{-- Шторка «Переставить» — общая у карточки «Наличия» и дела: открывает метка парковки (`move:open`, «Измайловский лес,
     A-3 ⌄»), внутри парковка и место (свободные — подсказкой по выбранной парковке, spots_controller), отправка — `Move`.
     Нужны `$vehicle`, `$yards` (id → имя), `$yardRows` (id → свободные места). --}}
<div data-controller="sheet" data-action="move:open@window->sheet#open" class="contents">
    <x-ui.sheet id="move-{{ $vehicle->id }}" title="Переставить">
        <form method="post" action="/cars/{{ $vehicle->id }}/move" class="flex flex-col gap-3">
            @csrf
            <div class="fields" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
                <x-park.yard-field :yards="$yards" :value="$vehicle->yard_id" required data-spots-target="yard" data-action="change->spots#sync"/>
                <x-ui.field name="spot" label="Место" :value="$vehicle->spot" list="move-spots-{{ $vehicle->id }}" autocapitalize="characters"/>
                <datalist id="move-spots-{{ $vehicle->id }}" data-spots-target="list"></datalist>
            </div>
            <x-ui.sheet-foot>Переставить</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
