{{-- Карточка строки таблицы площадок: чипы, карта мест, форма. --}}
<x-ui.detail>
    <x-ui.row-card :href="'/cars?yard='.$yard->id" :title="$yard->name" :photo="false" action="ТС на парковке">
        {{-- Закрыта — словом, адрес и места — фразой (10.10.2026: «что-то чипами, что-то текстом»). --}}
        @unless ($yard->is_active)<x-slot:badge><x-ui.state tone="closed" class="title-state">закрыта</x-ui.state></x-slot:badge>@endunless
        <x-slot:line>
            @if ($yard->settlement || $yard->address)<x-ui.place>{{ trim(($yard->settlement?->title() ?? '').', '.($yard->address ?? ''), ', ') }}</x-ui.place>@endif
            @if ($yard->capacity)<span class="nums text-ink-muted">{{ max(0, $yard->capacity - $yard->stored_vehicles_count) }} свободно из {{ $yard->capacity }}</span>@endif
        </x-slot:line>
        @if ($yard->rows)<div class="mt-4"><x-park.yard-map :yard="$yard" :occupied="$yard->storedVehicles->whereNotNull('spot')->keyBy('spot')"/></div>@endif
        @if (auth()->user()->isParkAdmin())<div class="mt-4">@include('park.yards.form', ['yard' => $yard])</div>@endif
    </x-ui.row-card>
</x-ui.detail>
