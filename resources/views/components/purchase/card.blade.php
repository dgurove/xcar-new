{{-- Машина закупки: та же карточка, что у оффера; вся карточка — ссылка. --}}
@props(['car', 'purchase', 'query' => '', 'showKind' => false])
@php
    $mine = $car->offerOf(auth()->user());
    $href = "/zakupki/{$purchase->number}/{$car->ref}".($query ? '?'.$query : '');
    $main = $car->mainPhoto();
    $photos = $car->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $hasMedia = $photos->isNotEmpty();
    $facts = array_filter([$car->year, $car->mileage !== null ? number_format($car->mileage, 0, '', ' ').' км' : null, $car->transmission?->label(), $car->fuel?->label()]);
@endphp
<article id="car-{{ $car->id }}" class="card rise group{{ $hasMedia ? '' : ' card--blank' }}">
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
    @endif
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="block min-w-0 flex-1 text-[17px] leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $car->titleWithYear() }}</span></a>
        </div>
        @if ($car->fssp)
            <div class="card-marks{{ $hasMedia ? '' : ' card-marks--flow' }}"><span class="mark mark-glass">Ограничения ФССП</span></div>
        @endif
    </div>
    <div class="card-extra">
        @if (!$mine)<span class="tag" style="--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d">Без цены</span>@endif
        @if ($showKind)<span class="tag">{{ $car->kind->label() }}</span>@endif
        @foreach ($facts as $fact)<span class="tag">{{ $fact }}</span>@endforeach
        @if ($car->settlement?->name ?? $car->city)<span class="text-sm text-ink-dim">{{ $car->settlement?->name ?? $car->city }}</span>@endif
        <span class="card-aside"><span class="nums text-sm font-normal text-ink-dim">{{ $car->dl }}</span></span>
    </div>
    <div class="card-action">
        @if ($mine)
            <a href="{{ $href }}" class="btn btn-s btn-accent nums w-full whitespace-nowrap">{{ number_format($mine->amount, 0, '', ' ') }} ₽</a>
        @else
            <a href="{{ $href }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">Предложить</a>
        @endif
    </div>
</article>
