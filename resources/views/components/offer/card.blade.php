{{-- Карточка оффера — одна разметка на строку и плитку, раскладку задаёт контейнер .cards
     (сетка областей: media, body, extra, star, aside, place, action). Закладка, цена и город —
     прямые дети карточки: закладка в правом верхнем углу, под ней цена, внизу кнопка, город
     слева вровень с кнопкой. Якорь живых обновлений: id и data-offer-number. Витрина; в CRM — x-offer.crm-card. --}}
@props(['offer', 'context' => null])
@php
    $user = auth()->user();
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $prices = $price->visible;
    $href = $context?->offerUrl($offer) ?? "/offers/{$n}";
    $main = $offer->mainPhoto();
    $photos = $offer->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $hasMedia = $photos->isNotEmpty();
    $canBid = ($user?->canBid() ?? false) && $offer->bidsOpen();
    // Покупатель: интерес уже отмечен? Каталог грузит его интерес одним запросом, иначе — точечно.
    $myInterest = $user?->isBuyer() ? ($offer->relationLoaded('interests') ? $offer->interests->firstWhere('user_id', $user->id) : $offer->interests()->where('user_id', $user->id)->first()) : null;
    $seen = $user?->isManager() && !$gallery ? \App\Offers\Showing::remembered($offer->id) : null;
    // «Показать покупателям» — значком рядом с «Подтвердить», число открытых — бейджем на нём (чипа «видят N» тогда нет).
    $canShow = $user?->isManager() && $offer->state === \App\Offers\OfferState::Open;
    $sizes = \App\Support\ListView::sizes(\App\Support\ListView::fromRequest(request()));
    // Первые две карточки страницы — кадр с высоким приоритетом (счётчик на запросе).
    $nth = request()->attributes->get('card.nth', 0);
    request()->attributes->set('card.nth', $nth + 1);
    $eager = $nth < 2;
    $bidder = $user?->canBid() ?? false;
    $hasMarks = $offer->car_place || (!$gallery && ($offer->isFresh() || ($bidder && ($offer->isEndingSoon() || !$offer->bidsOpen() || $offer->secondsLeft()))));
@endphp
<article id="offer-{{ $n }}" data-offer-number="{{ $n }}" {{ $attributes->merge(['class' => 'card group']) }}>
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
    @if ($user)<x-offer.favorite :offer="$offer"/>@endif
    @if (!$gallery && $user?->isManager())
        {{-- Кружок режима выбора: в разметке всегда, виден только когда лента в режиме (selection). --}}
        <label class="card-check"><input type="checkbox" value="{{ $offer->id }}" aria-label="Выбрать"><span><x-ui.icon name="check" class="size-4"/></span></label>
    @endif

    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="block min-w-0 flex-1 text-lg leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $offer->titleWithYear() }}@if ($offer->recommended)<x-offer.recommended/>@endif</span></a>
        </div>
        @if ($hasMarks)
            <div class="card-marks"><x-offer.marks :offer="$offer"/></div>
        @endif
    </div>

    <div class="card-extra">
        <x-offer.tags :offer="$offer"/>
        @if ($seen && !$canShow)<span class="tag nums">видят {{ $seen }}</span>@endif
    </div>
    <span class="card-aside">
        @if ($prices)
            @if ($price->shown())<span class="card-price nums" data-controller="fit">@if ($price->withFrom())<span class="card-price-from">{{ $price::money($price->from) }}&nbsp;→</span> @endif<span class="card-price-now">{{ $price::money($price->to) }}&nbsp;₽</span></span>@endif
            @if ($price->declared)<span class="tag nums">заявленная {{ $price::money($price->declared) }}</span>@endif
        @elseif ($gallery)
            <span class="text-sm text-accent-text">Скоро в продаже</span>
        @else
            <span class="text-sm text-accent-text">Узнать цену</span>
        @endif
    </span>
    <div class="card-place">@if ($offer->settlement)<x-ui.place class="truncate text-sm text-ink-dim">{{ $offer->settlement->title() }}</x-ui.place>@endif</div>

    <div class="card-action">
        {{-- Глагол — лаймовой капсулой .btn-get, переход («Открыть») и сделанное — тихой; чат и показ — значками. --}}
        @if ($user?->isBuyer())
            {{-- Кнопка ведёт на страницу и сразу открывает форму интереса (?interes=1). --}}
            <a href="{{ $myInterest ? $href : $href.(str_contains($href, '?') ? '&' : '?').'interes=1' }}" class="btn btn-get {{ $myInterest ? 'btn-get-done' : '' }}">{{ $myInterest ? 'Интерес отмечен' : 'Проявить интерес' }}</a>
        @elseif ($gallery || !$prices)
            <a href="{{ $href }}" class="btn btn-get {{ $offer->state->acceptsInterest() ? '' : 'btn-get-quiet' }}">{{ $gallery ? 'Проявить интерес' : 'Узнать цену' }}</a>
        @elseif ($canBid)
            <a href="{{ $href }}" class="btn btn-get">Подтвердить</a>
        @else
            <a href="{{ $href }}" class="btn btn-get btn-get-quiet">Открыть</a>
        @endif
        @if ($canShow)
            <a href="{{ $href }}" data-show-offer="{{ $offer->id }}" class="btn btn-icon relative" aria-label="{{ $seen ? 'Показано '.$seen.' покупателям' : 'Показать покупателям' }}"><x-ui.icon name="users" class="size-[22px]"/>@if ($seen)<span class="badge nums">{{ $seen }}</span>@endif</a>
        @endif
        @if (!$gallery && $user?->canChat() && $offer->chat_enabled)
            <a href="/account/chats/offer/{{ $offer->number }}" class="btn btn-icon" aria-label="Написать в чат"><x-ui.icon name="chat" class="size-[22px]"/></a>
        @endif
    </div>
</article>
