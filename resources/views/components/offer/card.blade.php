{{-- Карточка оффера — одна разметка на строку и плитку, раскладку задаёт
     контейнер .cards. Якорь живых обновлений: id и data-offer-number. --}}
@props(['offer', 'context' => null, 'admin' => false])
@php
    $user = auth()->user();
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $prices = !$gallery && ($user?->role->canSeePrices() ?? false);
    $href = $admin ? "/admin/offers/{$n}" : ($context?->offerUrl($offer) ?? "/offers/{$n}");
    $main = $offer->mainPhoto();
    $photos = $offer->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $hasMedia = $photos->isNotEmpty();
    $canBid = !$admin && ($user?->role->canBid() ?? false) && $offer->bidsOpen();
    $hasMarks = $admin || $offer->car_place || (!$gallery && ($offer->isFresh() || $offer->isEndingSoon() || !$offer->state->acceptsBids() || $offer->secondsLeft()));
@endphp
<article id="{{ $admin ? 'admin-offer-' : 'offer-' }}{{ $n }}" data-offer-number="{{ $n }}" {{ $attributes->merge(['class' => 'card rise group'.($hasMedia ? '' : ' card--blank')]) }}>
    @if ($hasMedia)
        <div class="card-media" data-controller="frames" data-action="cards:tick@window->frames#next cards:stop@window->frames#stop touchstart->frames#start:passive touchend->frames#end:passive">
            <a href="{{ $href }}" class="block h-full w-full" data-action="frames#click">
                @foreach ($photos as $i => $frame)
                    <x-offer.photo :media="$frame" sizes="(min-width: 1024px) 320px, (min-width: 640px) 45vw, 100vw" :eager="false" data-frames-target="frame" :hidden="$i > 0"/>
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
            <a href="{{ $href }}" class="block min-w-0 flex-1 text-[17px] leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $offer->titleWithYear() }}</span></a>
            @if ($user && !$admin)<x-offer.favorite :offer="$offer"/>@endif
        </div>
        @if ($hasMarks)
            <div class="card-marks{{ $hasMedia ? '' : ' card-marks--flow' }}"><x-offer.marks :offer="$offer" :admin="$admin"/></div>
        @endif
    </div>

    <div class="card-extra">
        <x-offer.tags :offer="$offer"/>
        @if ($offer->settlement)<span class="text-sm text-ink-dim">{{ $offer->settlement->name }}</span>@endif
        <span class="card-aside">
            @if ($prices || $admin)
                @if ($offer->asking_price)<span class="card-price nums"><span class="card-price-now">{{ number_format($offer->asking_price, 0, '', ' ') }} ₽</span></span>@endif
            @elseif ($gallery)
                <span class="text-sm text-accent-text">Скоро в продаже</span>
            @else
                <span class="text-sm text-accent-text">Узнать цену</span>
            @endif
        </span>
    </div>

    <div class="card-action">
        @if ($admin)
            <x-ui.pill :tone="$offer->state->tone()" class="w-full">{{ $offer->state->label() }}</x-ui.pill>
            @if ($offer->active_bids_count ?? 0)<span class="badge">{{ $offer->active_bids_count }}</span>@endif
        @elseif ($gallery || !$prices)
            <a href="{{ $href }}" class="btn btn-s {{ $offer->state->acceptsInterest() ? 'btn-accent' : 'btn-quiet' }} w-full whitespace-nowrap">{{ $gallery ? 'Проявить интерес' : 'Узнать цену' }}</a>
        @elseif ($canBid)
            <a href="{{ $href }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">Подтвердить</a>
        @else
            <a href="{{ $href }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">Открыть</a>
        @endif
        @if (!$gallery && !$admin && $user && $offer->chat_enabled)
            <a href="{{ $href }}{{ str_contains($href, '?') ? '&' : '?' }}chat=1" class="btn btn-s btn-quiet btn-round" aria-label="Написать в чат"><x-ui.icon name="chat" class="size-5"/></a>
        @endif
    </div>
</article>
