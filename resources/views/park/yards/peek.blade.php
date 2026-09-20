{{-- Окошко строки таблицы площадок: чипы, карта мест, форма. --}}
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="'/cars?yard='.$yard->id" :title="$yard->name" :photo="false" action="ТС на парковке">
        <x-slot:marks>
            @if ($yard->settlement || $yard->address)<x-ui.place class="tag">{{ trim(($yard->settlement?->name ?? '').', '.($yard->address ?? ''), ', ') }}</x-ui.place>@endif
            @if ($yard->capacity)<span class="tag nums">{{ max(0, $yard->capacity - $yard->stored_vehicles_count) }} свободно из {{ $yard->capacity }}</span>@endif
            @unless ($yard->is_active)<span class="tag">закрыта</span>@endunless
        </x-slot:marks>
        @if ($yard->rows)<div class="mt-4"><x-park.yard-map :yard="$yard" :occupied="$yard->storedVehicles->whereNotNull('spot')->keyBy('spot')"/></div>@endif
        <div class="mt-4">@include('park.yards.form', ['yard' => $yard])</div>
    </x-ui.peek>
</turbo-frame>
