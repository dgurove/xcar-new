{{-- ТС и где забирать: одна карточка на анкете, пропуске и странице скана. --}}
@php $yard = $vehicle->yard; @endphp
<div class="mt-5 rounded-(--radius-l) bg-surface-2 p-4">
    <div class="text-lg font-medium leading-snug">{{ $vehicle->titleWithYear() }}</div>
    @if ($vehicle->plate)<div class="nums mt-0.5 text-sm text-ink-muted">{{ $vehicle->plate }}</div>@endif
    @if ($yard)
        <a href="{{ $yard->mapUrl() }}" target="_blank" rel="noopener" class="mt-3 flex items-start gap-2 text-sm text-ink hover:text-accent-text">
            <x-ui.icon name="map-pin" class="mt-0.5 size-4 shrink-0 text-accent-text"/>
            <span><span class="block">{{ $yard->name }}</span><span class="block text-ink-muted">{{ $yard->fullAddress() }}</span></span>
        </a>
    @endif
    {{ $slot ?? '' }}
</div>
