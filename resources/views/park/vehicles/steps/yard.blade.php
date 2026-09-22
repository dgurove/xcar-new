{{-- Шаг «Нужно указать парковку»: ТС заведена без площадки (по письмам или по факту), стоит там со дня приёма. --}}
<x-ui.card class="mt-3">
    <div class="grid grid-cols-2 gap-3" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
        <x-ui.field name="yard_id" label="Парковка" :options="$yards" placeholder="—" :value="old('yard_id')" required data-spots-target="yard" data-action="change->spots#sync"/>
        <x-ui.field name="spot" label="Место" list="spots-list" autocapitalize="characters"/>
        <datalist id="spots-list" data-spots-target="list"></datalist>
    </div>
</x-ui.card>
<div class="mt-4"><x-ui.button id="act-submit" class="w-full sm:w-auto">Указать</x-ui.button></div>
