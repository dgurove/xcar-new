{{-- Шаг «Нужно указать парковку»: ТС заведена без площадки (по письмам или по факту), стоит там со дня приёма. Своя форма
     (`SetYard`): формы текущего шага вокруг таймлайна больше нет. --}}
<form method="post" action="/cars/{{ $vehicle->id }}/yard">
    @csrf
    <div class="mt-2">
        <div class="grid grid-cols-2 gap-2" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
            <x-park.yard-field :yards="$yards" placeholder="—" :value="old('yard_id')" required data-spots-target="yard" data-action="change->spots#sync"/>
            <x-ui.field name="spot" label="Место" list="spots-list" autocapitalize="characters"/>
            <datalist id="spots-list" data-spots-target="list"></datalist>
            @include('park.yards.plan-pick-frame', ['pick' => $vehicle->id, 'at' => null])
        </div>
    </div>
    <div class="mt-2 flex"><button class="btn btn-s btn-accent flex-1">Указать</button></div>
</form>
