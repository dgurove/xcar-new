{{-- Машина стоянки — та же карточка, что у оффера: кадр, название с госномером, пилюли состояния, действие. --}}
@props(['vehicle', 'href' => null])
@php
    $href ??= '/mashiny/'.$vehicle->id;
    $main = $vehicle->mainPhoto();
    $photos = $vehicle->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $hasMedia = $photos->isNotEmpty();
    $state = $vehicle->state;
@endphp
<article id="vehicle-{{ $vehicle->id }}" class="card rise group">
    @if ($hasMedia)
        <div class="card-media" data-controller="frames" data-action="cards:tick@window->frames#next cards:stop@window->frames#stop touchstart->frames#start:passive touchend->frames#end:passive">
            <a href="{{ $href }}" class="block h-full w-full" data-action="frames#click">
                @foreach ($photos as $i => $frame)
                    <x-offer.photo :media="$frame" sizes="(min-width: 1024px) 320px, (min-width: 640px) 45vw, 100vw" data-frames-target="frame" :hidden="$i > 0"/>
                @endforeach
            </a>
            @if ($photos->count() > 1)
                <div class="card-frames">
                    <div class="pointer-events-none absolute inset-x-0 bottom-3 flex justify-center gap-1">
                        @foreach ($photos as $i => $frame)<span class="card-dot{{ $i === 0 ? ' card-dot--on' : '' }}" data-frames-target="dot"></span>@endforeach
                    </div>
                    <button type="button" class="card-arrow left-2 hidden sm:flex" data-action="frames#prev" aria-label="Предыдущее фото"><x-ui.icon name="chevron-left" class="size-[18px]"/></button>
                    <button type="button" class="card-arrow right-2 hidden sm:flex" data-action="frames#manual" aria-label="Следующее фото"><x-ui.icon name="chevron-right" class="size-[18px]"/></button>
                </div>
            @endif
        </div>
    @else
        <a href="{{ $href }}" class="card-media card-media--blank" tabindex="-1"><x-ui.car-blank/></a>
    @endif
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="block min-w-0 flex-1 text-[17px] leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $vehicle->titleWithYear() }}@if ($vehicle->plate) <span class="nums font-normal text-ink-muted">{{ $vehicle->plate }}</span>@endif</span></a>
        </div>
        <div class="card-marks">
            <span class="mark {{ match ($state->tone()) { 'open' => 'mark-accent', 'urgent' => 'mark-urgent', default => 'mark-glass' } }}">{{ $state->label() }}</span>
            @if ($state === \App\Park\VehicleState::Stored)<span class="mark mark-glass nums">{{ $vehicle->daysStored() }} дн.</span>@endif
        </div>
    </div>
    <div class="card-extra">
        @if ($vehicle->ref)<span class="tag nums font-normal">{{ $vehicle->ref }}</span>@endif
        @if ($vehicle->vin)<span class="tag nums font-normal">{{ $vehicle->vin }}</span>@endif
        @if ($vehicle->client)<span class="tag">{{ $vehicle->client->name }}</span>@endif
        @if ($state === \App\Park\VehicleState::Stored && $vehicle->yard)<span class="text-sm text-ink-dim">{{ $vehicle->yard->name }}</span>@endif
        @if ($state === \App\Park\VehicleState::Released && $vehicle->released_at)<span class="nums text-sm font-normal text-ink-dim">выдана {{ $vehicle->released_at->translatedFormat('j M Y') }}</span>@endif
        {{ $slot }}
    </div>
    <div class="card-action">
        <a href="{{ $href }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">{{ $state === \App\Park\VehicleState::Expected ? 'Принять' : ($state === \App\Park\VehicleState::Stored ? 'Открыть' : 'Карточка') }}</a>
    </div>
</article>
