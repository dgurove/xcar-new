{{-- Строка машины: фото, название, номер, где стоит и сколько. --}}
@props(['vehicle', 'href' => null])
<a href="{{ $href ?? '/mashiny/'.$vehicle->id }}" class="row items-start">
    <div class="row-photo"><x-offer.photo :media="$vehicle->mainPhoto()" sizes="64px"/></div>
    <div class="min-w-0 flex-1">
        <div class="flex items-baseline gap-2">
            <span class="truncate font-medium">{{ $vehicle->titleWithYear() }}</span>
            @if ($vehicle->plate)<span class="shrink-0 text-sm text-ink-muted">{{ $vehicle->plate }}</span>@endif
        </div>
        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
            @if ($vehicle->ref)<span class="tag nums">{{ $vehicle->ref }}</span>@endif
            @if ($vehicle->client)<span class="tag">{{ $vehicle->client->name }}</span>@endif
            {{ $slot }}
        </div>
    </div>
</a>
