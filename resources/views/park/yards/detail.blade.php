{{-- Карточка строки таблицы площадок: чипы, схема (нажатие — во весь экран), форма. --}}
<x-ui.detail>
    <x-ui.row-card :href="'/cars?yard='.$yard->id" :title="$yard->name" :photo="false" action="ТС на парковке">
        {{-- Закрыта — словом, адрес и места — фразой (10.10.2026: «что-то чипами, что-то текстом»). --}}
        @unless ($yard->is_active)
            <x-slot:badge><x-ui.state tone="closed" class="title-state">закрыта</x-ui.state></x-slot:badge>
        @endunless
        <x-slot:line>
            @if ($yard->settlement || $yard->address)<x-ui.place>{{ trim(($yard->settlement?->title() ?? '').', '.($yard->address ?? ''), ', ') }}</x-ui.place>@endif
            @if ($yard->capacity)<span class="nums text-ink-muted">{{ max(0, $yard->capacity - $yard->stored_vehicles_count) }} свободно из {{ $yard->capacity }}</span>@endif
        </x-slot:line>
        @if ($yard->rows)<a href="/yards/{{ $yard->id }}/plan" class="plan-tile-link mt-4" aria-label="Схема"><x-park.yard-plan :yard="$yard" :cars="$yard->storedVehicles->whereNotNull('spot')->keyBy('spot')" mode="tile"/></a>@endif
        @if (! $yard->rows && auth()->user()->editsYardPlan())<a href="/yards/{{ $yard->id }}/plan/edit" class="btn btn-quiet mt-4 w-full">Нарисовать схему</a>@endif
        @if (auth()->user()->isParkAdmin())<div class="mt-4">@include('park.yards.form', ['yard' => $yard])</div>@endif
    </x-ui.row-card>
</x-ui.detail>
