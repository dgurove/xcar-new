{{-- Парковка строкой плашки .list: название, адрес, «Маршрут» — открывает карту. --}}
@props(['yard'])
@if ($yard)
    <a href="{{ $yard->mapUrl() }}" target="_blank" rel="noopener" class="row justify-between">
        <span class="min-w-0"><span class="block">{{ $yard->name }}</span><span class="block truncate text-sm text-ink-muted">{{ $yard->fullAddress() }}</span></span>
        <span class="flex shrink-0 items-center gap-1 text-accent-text">Маршрут<x-ui.icon name="chevron-right" class="size-4"/></span>
    </a>
@endif
