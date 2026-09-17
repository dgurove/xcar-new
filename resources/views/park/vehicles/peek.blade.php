{{-- Окошко строки таблицы машин стоянки (фрейм peek). --}}
@php
    use App\Park\VehicleState;
    $state = $vehicle->state;
    $href = '/cars/'.$vehicle->id;
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$href" :title="$vehicle->titleWithYear()" :photo="$vehicle->mainPhoto()" :action="$state === VehicleState::Expected ? 'Принять' : 'Открыть'">
        <x-slot:marks>
            <x-ui.pill :tone="$state->tone()" class="!min-h-0 !py-1 text-xs">{{ $state->label() }}</x-ui.pill>
            @if ($vehicle->plate)<span class="tag nums">{{ $vehicle->plate }}</span>@endif
            @if ($vehicle->ref)<span class="tag">{{ $vehicle->ref }}</span>@endif
            @if ($vehicle->vin)<span class="tag nums">{{ $vehicle->vin }}</span>@endif
            @if ($vehicle->client)<span class="tag">{{ $vehicle->client->name }}</span>@endif
            @if ($vehicle->yard)<span class="tag">{{ $vehicle->yard->name }}</span>@endif
            @if ($vehicle->accepted_at)<span class="tag nums">принята {{ $vehicle->accepted_at->translatedFormat('j M Y') }}</span>@endif
            @if ($state === VehicleState::Released && $vehicle->released_at)<span class="tag nums">выдана {{ $vehicle->released_at->translatedFormat('j M Y') }}</span>@endif
        </x-slot:marks>
        <x-slot:aside>
            @if ($state === VehicleState::Stored && $vehicle->daysStored() !== null)<span class="nums text-lg font-bold">{{ $vehicle->daysStored() }} {{ \App\Support\Plural::of($vehicle->daysStored(), ['день', 'дня', 'дней']) }}</span>@endif
        </x-slot:aside>
    </x-ui.peek>
</turbo-frame>
