{{-- Машина закупки: та же карточка, что у оффера; вся карточка — ссылка. --}}
@props(['car', 'purchase', 'query' => '', 'showKind' => false])
@php
    $staff = auth()->user()?->isStaff() ?? false;
    $mine = $staff ? null : $car->offerOf(auth()->user());
    $best = $staff ? $car->bestOffer() : null;
    $active = $staff ? $car->offers->whereIn('state', [\App\Purchases\OfferState::Active, \App\Purchases\OfferState::Chosen]) : collect();
    $href = "/purchases/{$purchase->number}/{$car->ref}".($query ? '?'.$query : '');
    $main = $car->mainPhoto();
    $photos = $car->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $hasMedia = $photos->isNotEmpty();
    $facts = array_filter([$car->year, $car->mileage !== null ? \App\Support\Money::nums($car->mileage).' км' : null, $car->transmission?->label(), $car->fuel?->label()]);
@endphp
<article id="car-{{ $car->id }}" class="card rise group">
    @if ($hasMedia)
        <div class="card-media" data-controller="frames" data-action="cards:tick@window->frames#next cards:stop@window->frames#stop">
            <a href="{{ $href }}" class="card-strip" data-frames-target="strip" data-action="frames#click touchstart->frames#touch:passive">
                @foreach ($photos as $i => $frame)
                    <x-offer.photo :media="$frame" sizes="(min-width: 1024px) 320px, (min-width: 640px) 45vw, 100vw" data-frames-target="frame"/>
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
            <a href="{{ $href }}" class="block min-w-0 flex-1 text-lg leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $car->titleWithYear() }}</span></a>
        </div>
        @if ($car->fssp)
            <div class="card-marks"><span class="mark mark-glass">Ограничения ФССП</span></div>
        @endif
    </div>
    <div class="card-extra">
        @if (!$staff && !$mine)<span class="tag" style="--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d">Без цены</span>@endif
        @if ($showKind)<span class="tag">{{ $car->kind->label() }}</span>@endif
        @foreach ($facts as $fact)<span class="tag">{{ $fact }}</span>@endforeach
    </div>
    <span class="card-aside"><span class="nums text-sm font-normal text-ink-dim">{{ $car->dl }}</span></span>
    <div class="card-place">@if ($car->settlement?->name ?? $car->city)<x-ui.place class="truncate text-sm text-ink-dim">{{ $car->settlement?->name ?? $car->city }}</x-ui.place>@endif</div>
    <div class="card-action">
        @if ($staff)
            @if ($best)
                <a href="{{ $href }}" class="btn btn-s {{ $best->state === \App\Purchases\OfferState::Chosen ? 'btn-accent' : 'btn-quiet' }} w-full gap-2 whitespace-nowrap"><span class="nums">{{ \App\Support\Money::rub($best->amount) }}</span><span class="truncate font-normal opacity-80">{{ $best->user->shortName() }}{{ $active->count() > 1 ? ' +'.($active->count() - 1) : '' }}</span></a>
            @else
                <a href="{{ $href }}" class="btn btn-s btn-ghost w-full whitespace-nowrap text-ink-dim">Нет предложений</a>
            @endif
        @elseif ($mine)
            <a href="{{ $href }}" class="btn btn-s btn-accent nums w-full whitespace-nowrap">{{ \App\Support\Money::rub($mine->amount) }}</a>
        @else
            <a href="{{ $href }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">Предложить</a>
        @endif
    </div>
</article>
