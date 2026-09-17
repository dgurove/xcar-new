{{-- Строки живого поиска в шторке: фото, название, цена; тап — на предложение. --}}
<turbo-frame id="search-results">
    @if ($offers->isNotEmpty())
        <div class="flex flex-col gap-1.5">
            @foreach ($offers as $offer)
                <a href="/offers/{{ $offer->number }}" class="row" data-turbo-frame="_top">
                    <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="4.5rem"/></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $offer->titleWithYear() }}@if ($offer->recommended)<x-offer.recommended/>@endif</span>
                        <span class="mt-1 flex flex-wrap gap-1.5"><span class="tag nums">№ {{ $offer->number }}</span>@if ($offer->mileage)<span class="tag nums">{{ \App\Support\Money::nums($offer->mileage) }} км</span>@endif</span>
                    </span>
                    @php($price = \App\Offers\PriceView::for($offer, auth()->user()))@if ($price->shown())<span class="nums shrink-0 text-sm font-semibold">{{ $price::money($price->to) }}&nbsp;₽</span>@endif
                </a>
            @endforeach
        </div>
    @elseif (mb_strlen($q) >= 2)
        <p class="px-1 py-6 text-center text-sm text-ink-muted">Ничего не нашлось</p>
    @endif
</turbo-frame>
