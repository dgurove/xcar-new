{{-- Карточка оффера — одна разметка на строку и плитку, раскладку задаёт
     контейнер .cards. Якорь живых обновлений: id и data-offer-number. --}}
@props(['offer', 'context' => null, 'admin' => false])
@php
    $user = auth()->user();
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $prices = !$gallery && ($user?->role->canSeePrices() ?? false);
    $href = $admin ? "/predlozheniya/{$n}" : ($context?->offerUrl($offer) ?? "/offers/{$n}");
    $main = $offer->mainPhoto();
    $photos = $offer->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $hasMedia = $photos->isNotEmpty();
    $canBid = !$admin && ($user?->role->canBid() ?? false) && $offer->bidsOpen();
    $sizes = \App\Support\ListView::sizes(\App\Support\ListView::fromRequest(request()));
    // Первые две карточки страницы — кадр с высоким приоритетом (счётчик на запросе).
    $nth = request()->attributes->get('card.nth', 0);
    request()->attributes->set('card.nth', $nth + 1);
    $eager = $nth < 2;
    $hasMarks = $admin || $offer->car_place || (!$gallery && ($offer->isFresh() || $offer->isEndingSoon() || !$offer->state->acceptsBids() || $offer->secondsLeft()));
@endphp
<article id="{{ $admin ? 'admin-offer-' : 'offer-' }}{{ $n }}" data-offer-number="{{ $n }}" {{ $attributes->merge(['class' => 'card rise group']) }}>
    @if ($hasMedia)
        <div class="card-media" data-controller="frames" data-action="cards:tick@window->frames#next cards:stop@window->frames#stop">
            <a href="{{ $href }}" class="card-strip" data-frames-target="strip" data-action="frames#click touchstart->frames#touch:passive">
                @foreach ($photos as $i => $frame)
                    <x-offer.photo :media="$frame" :sizes="$sizes" :eager="$eager && $i === 0" data-frames-target="frame"/>
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
            <a href="{{ $href }}" class="block min-w-0 flex-1 text-[17px] leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $offer->titleWithYear() }}</span></a>
            @if ($user && !$admin)<x-offer.favorite :offer="$offer"/>@endif
        </div>
        @if ($hasMarks)
            <div class="card-marks"><x-offer.marks :offer="$offer" :admin="$admin"/></div>
        @endif
        @if ($admin && $gallery && ($offer->interests_count ?? 0))
            <div class="mt-1 text-sm text-accent-text">{{ $offer->interests_count }} {{ \App\Support\Plural::of($offer->interests_count, ['интерес', 'интереса', 'интересов']) }}</div>
        @elseif ($admin && !$gallery && ($offer->active_bids_count ?? 0))
            <div class="mt-1 text-sm text-urgent">{{ $offer->active_bids_count }} {{ \App\Support\Plural::of($offer->active_bids_count, ['подтверждение', 'подтверждения', 'подтверждений']) }}@if ($offer->top_bid) <span class="tag nums">до {{ number_format($offer->top_bid, 0, '', ' ') }} ₽</span>@endif</div>
        @endif
    </div>

    <div class="card-extra">
        <x-offer.tags :offer="$offer"/>
        @if ($offer->settlement)<span class="text-sm text-ink-dim">{{ $offer->settlement->name }}</span>@endif
        <span class="card-aside">
            @if ($prices || $admin)
                @if ($offer->asking_price)<span class="card-price nums" data-controller="fit">@if ($user?->isStaff() && $offer->floor_price)<span class="card-price-from">{{ number_format($offer->floor_price, 0, '', ' ') }}</span> → @endif<span class="card-price-now">{{ number_format($offer->asking_price, 0, '', ' ') }}&nbsp;₽</span></span>@endif
            @elseif ($gallery)
                <span class="text-sm text-accent-text">Скоро в продаже</span>
            @else
                <span class="text-sm text-accent-text">Узнать цену</span>
            @endif
        </span>
    </div>

    @unless ($admin)
    <div class="card-action">
        @if ($gallery || !$prices)
            <a href="{{ $href }}" class="btn btn-s {{ $offer->state->acceptsInterest() ? 'btn-accent' : 'btn-quiet' }} w-full whitespace-nowrap">{{ $gallery ? 'Проявить интерес' : 'Узнать цену' }}</a>
        @elseif ($canBid)
            <a href="{{ $href }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">Подтвердить</a>
        @else
            <a href="{{ $href }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">Открыть</a>
        @endif
        @if (!$gallery && $user && !$user->isStaff() && $offer->chat_enabled)
            <a href="{{ $href }}{{ str_contains($href, '?') ? '&' : '?' }}chat=1" class="btn btn-s btn-quiet btn-round" aria-label="Написать в чат"><x-ui.icon name="chat" class="size-5"/></a>
        @endif
    </div>
    @endunless
</article>
