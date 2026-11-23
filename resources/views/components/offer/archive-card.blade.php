{{-- Плитка архива менеджера (`Offer::scopeArchivedFor`, 23.11.2026, начальник: «видеть, почему она туда попала, они не должны,
     просто архив»): раскладка `.card` та же, что у `x-offer.card` (кадр, название, цена, город, кнопка), но без всего, что говорит
     о судьбе ТС, — ни срока приёма, ни меток, ни избранного, ни своей цены, ни «Просмотрено»; цена — как была на витрине.
     Якоря живых обновлений (`data-offer-number`) нет: события каталога сняли бы плитку. Главное — «Написать» (чат по ТС с площадкой). --}}
@props(['offer', 'view' => null])
@php
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    $href = "/offers/{$offer->number}";
    $photos = $offer->visiblePhotos()->take(6)->values();
@endphp
<article {{ $attributes->merge(['class' => 'card group']) }}>
    <x-ui.card-media :photos="$photos" :href="$href" :cat="$offer->category()->icon()" :sizes="\App\Support\ListView::sizes($view)"/>

    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="card-name block min-w-0 flex-1 leading-snug hover:text-accent-text"><span>{{ $offer->titleWithYear() }}</span></a>
        </div>
    </div>

    <span class="card-aside">
        @if ($price->shown())<span class="card-price nums" data-controller="fit">@if ($price->withFrom())<span class="card-price-from">{{ $price::money($price->from) }}&nbsp;→</span> @endif<span class="card-price-now">{{ $price::money($price->to) }}&nbsp;₽</span></span>@endif
    </span>
    <div class="card-place">@if ($offer->saleCity())<x-ui.place class="truncate text-sm text-ink-dim">{{ $offer->saleCity()->title() }}</x-ui.place>@endif</div>

    <div class="card-action">
        {{-- Чат по архивному открыт всем, кому он в архиве (`Offer::chatOpenFor`), — нужен только сам чат у человека. --}}
        @if (auth()->user()?->canChat())
            <a href="{{ \App\Chats\Chat::offerPath($offer->number) }}" class="btn btn-get">Написать</a>
        @else
            <a href="{{ $href }}" class="btn btn-get btn-get-quiet">Открыть</a>
        @endif
    </div>
</article>
