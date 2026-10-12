{{-- Карточка оффера — одна разметка на строку и плитку, раскладку задаёт контейнер .cards
     (сетка областей: media, body, extra, star, aside, place, action). Закладка, цена и город —
     прямые дети карточки: закладка в правом верхнем углу, под ней цена, внизу кнопка, город
     слева вровень с кнопкой. Якорь живых обновлений: id и data-offer-number. Витрина; в CRM — x-offer.crm-card. --}}
@props(['offer', 'context' => null, 'estimate' => null, 'only' => false])
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
    $canBid = ($user?->bidsOnSite() ?? false) && ($offer->bidsOpen() || $offer->canBidInDeal($user));
    // Интерес уже отмечен? У покупателя, а в галерее у всех (08.10.2026). Каталог грузит его одним запросом, иначе — точечно.
    $myInterest = $user && ($user->isBuyer() || $gallery) ? ($offer->relationLoaded('interests') ? $offer->interests->firstWhere('user_id', $user->id) : $offer->interests()->where('user_id', $user->id)->first()) : null;
    $seen = $user?->isManager() && !$gallery ? \App\Offers\Showing::remembered($offer->id) : null;
    // «Открыть покупателям» — значком рядом с «Подтвердить», число видящих — бейджем на нём (чипа «видят N» тогда нет); только
    // когда есть покупатели в «Выбранных» (12.10.2026), иначе все и так видят всё — чип «видят N».
    $canShow = $user?->isManager() && $offer->state === \App\Offers\OfferState::Open && \App\Offers\Showing::pickable($user);
    $sizes = \App\Support\ListView::sizes(\App\Support\ListView::fromRequest(request()));
    // Первые две карточки страницы — кадр с высоким приоритетом (счётчик на запросе).
    $nth = request()->attributes->get('card.nth', 0);
    request()->attributes->set('card.nth', $nth + 1);
    $eager = $nth < 2;
    $bidder = $user?->bidsOnSite() ?? false;
    $hasMarks = (bool) $offer->car_place;
    // Горящее важнее нового: полоса у плитки под кадром, точка у строки (владелец 10.10.2026). Горящее — тем, кто видит срок.
    // Отдано на оценку (11.10.2026): ход менеджера — оранжевой полосой, как горящее; оценил — без полосы, ждём админа.
    // Открыто только ему («Открыты только Вам», 11.10.2026) — красной полосой, важнее нового и горящего.
    $tone = match (true) {
        $estimate !== null => $estimate->isGiven() ? null : 'hot',
        $only => 'only',
        $gallery => null,
        $bidder && $offer->isEndingSoon() => 'hot',
        $offer->isFresh() => 'fresh',
        default => null,
    };
    // Своё действующее подтверждение — «Ваша цена» вместо «Подтвердить». Каталог догружает его одним запросом.
    $myBid = $bidder && !$gallery ? ($offer->relationLoaded('bids') ? $offer->bids->firstWhere('user_id', $user->id)
        : $offer->bids()->where('user_id', $user->id)->whereIn('state', [\App\Offers\BidState::Active, \App\Offers\BidState::Accepted])->first()) : null;
@endphp
<article id="offer-{{ $n }}" data-offer-number="{{ $n }}" {{ $attributes->merge(['class' => 'card group'.($tone ? ' card--'.$tone : '')]) }}>
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
    @if ($user && ! $estimate)<x-offer.favorite :offer="$offer"/>@endif
    @if (!$gallery && ! $estimate && $canShow)
        {{-- Кружок режима выбора: в разметке всегда, виден только когда лента в режиме (selection). --}}
        <label class="card-check"><input type="checkbox" value="{{ $offer->id }}" aria-label="Выбрать"><span><x-ui.icon name="check" class="size-4"/></span></label>
    @endif

    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="card-name block min-w-0 flex-1 leading-snug hover:text-accent-text"><span>@if ($tone)<span class="card-tone card-tone--{{ $tone }}" aria-label="{{ $estimate ? 'Нужно оценить' : match ($tone) { 'only' => 'Только вам', 'hot' => 'Горящее', default => 'Новое' } }}"></span>@endif{{ $offer->titleWithYear() }}@if ($offer->recommended)<x-offer.recommended/>@endif</span></a>
        </div>
        @if ($estimate)
            {{-- Срок оценки на месте срока приёма; отклонили — словом впереди, срок прежний. --}}
            @unless ($estimate->isGiven())<span class="card-when flex-wrap gap-x-1.5">@if ($estimate->rejected_at)<span class="whitespace-nowrap text-urgent">отклонили,</span>@endif<span class="inline-flex items-center gap-1 whitespace-nowrap"><x-ui.icon name="timer" class="size-3.5 {{ $estimate->isOverdue() ? 'text-danger' : 'text-ink-muted' }}"/><x-offer.estimate-left :estimate="$estimate"/></span></span>@endunless
        @else
        <x-offer.when :offer="$offer"/>
        @endif
        @if ($hasMarks)
            <div class="card-marks"><x-offer.marks :offer="$offer"/></div>
        @endif
    </div>

    <div class="card-extra">
        <x-offer.tags :offer="$offer" :extra="$seen && !$canShow ? ['видят '.$seen] : []"/>
    </div>
    <span class="card-aside">
        @if ($estimate)
            {{-- Как цена обычной плитки — «заявленная → продажи» (владелец 11.10.2026): у оценки на месте цены «Нужна оценка»,
                 у корректировки — цена XCar, у оценённой («Ждут решения») — его сумма, кнопка — «Изменить». Заявленную скрыли при просьбе — без неё и без стрелки. Закупочной на сайте нет. --}}
            @php $from = $estimate->showsDeclared() ? $offer->declaredPrice() : null; @endphp
            <span class="card-price nums" data-controller="fit">
                @if ($from)<span class="card-price-from">{{ $price::money($from) }}&nbsp;→</span>@endif
                @if ($estimate->isGiven())<span class="card-price-now">{{ $price::money($estimate->amount) }}&nbsp;₽</span>
                @elseif ($estimate->isCorrection())<span class="card-price-now">{{ $price::money($estimate->base_price) }}&nbsp;₽</span>
                @else<span class="card-price-now font-normal text-urgent">Нужна оценка</span>@endif
            </span>
        @elseif ($prices)
            @if ($price->shown())<span class="card-price nums" data-controller="fit">@if ($price->withFrom())<span class="card-price-from">{{ $price::money($price->from) }}&nbsp;→</span> @endif<span class="card-price-now">{{ $price::money($price->to) }}&nbsp;₽</span></span>@endif
            @if ($price->declared)<span class="nums text-xs text-ink-dim">заявленная {{ $price::money($price->declared) }}</span>@endif
        @elseif ($gallery)
            {{-- В «Галерее» срок понятен и так; в «Избранном» и поиске рядом сроки приёма — говорим, чего осталось. --}}
            <x-offer.gallery-left :offer="$offer" class="text-sm" :word="request()->is('gallery') ? 'осталось' : 'в галерее ещё'"/>
        @else
            <span class="text-sm text-accent-text">Узнать цену</span>
        @endif
    </span>
    <div class="card-place">@if ($offer->settlement)<x-ui.place class="truncate text-sm text-ink-dim">{{ $offer->settlement->title() }}</x-ui.place>@endif</div>

    <div class="card-action">
        {{-- Глагол — лаймовой капсулой .btn-get, переход («Открыть») и сделанное — тихой; чат и показ — значками. --}}
        @if ($estimate)
            <a href="{{ $href }}" class="btn btn-get nums {{ $estimate->isGiven() ? 'btn-get-quiet' : '' }}">{{ $estimate->isGiven() ? 'Изменить' : ($estimate->isCorrection() ? 'Проверить цену' : 'Оценить') }}</a>
        @elseif ($user?->isBuyer())
            {{-- Кнопка ведёт на страницу и сразу открывает форму интереса (?interes=1). --}}
            <a href="{{ $myInterest ? $href : $href.(str_contains($href, '?') ? '&' : '?').'interes=1' }}" class="btn btn-get {{ $myInterest ? 'btn-get-done' : '' }}">{{ $gallery ? ($myInterest ? 'Сообщим о продаже' : 'Сообщить о продаже') : ($myInterest ? 'Интерес отмечен' : 'Проявить интерес') }}</a>
        @elseif ($gallery || !$prices)
            <a href="{{ $href }}" class="btn btn-get {{ $myInterest ? 'btn-get-done' : ($offer->acceptsInterest() ? '' : 'btn-get-quiet') }}">{{ $gallery ? ($myInterest ? 'Интерес отмечен' : 'Проявить интерес') : 'Узнать цену' }}</a>
        @elseif ($myBid)
            <a href="{{ $href }}" class="btn btn-get btn-get-quiet nums">{{ $myBid->isGarage() ? 'В гараж' : 'Ваша цена '.\App\Support\Money::nums($myBid->amount) }}</a>
        @elseif ($canBid)
            <a href="{{ $href }}" class="btn btn-get">Подтвердить</a>
        @else
            <a href="{{ $href }}" class="btn btn-get btn-get-quiet">Открыть</a>
        @endif
        @if ($canShow)
            <a href="{{ $href }}" data-show-offer="{{ $offer->id }}" class="btn btn-icon relative" aria-label="{{ $seen ? 'Открыто '.$seen.' покупателям' : 'Открыть покупателям' }}"><x-ui.icon name="users" class="size-[22px]"/>@if ($seen)<span class="badge nums">{{ $seen }}</span>@endif</a>
        @endif
        @if (!$gallery && ! $estimate && $user?->canChat() && $offer->chat_enabled)
            <a href="/account/chats/offer/{{ $offer->number }}" class="btn btn-icon" aria-label="Написать в чат"><x-ui.icon name="chat" class="size-[22px]"/></a>
        @endif
    </div>
</article>
