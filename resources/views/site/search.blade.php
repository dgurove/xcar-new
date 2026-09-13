{{-- Строки живого поиска в шторке: фото, название, цена; тап — на предложение. --}}
<turbo-frame id="search-results">
    @if ($offers->isNotEmpty())
        <div class="flex flex-col gap-1.5">
            @foreach ($offers as $offer)
                <a href="/offers/{{ $offer->number }}" class="row" data-turbo-frame="_top">
                    <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="4.5rem"/></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $offer->titleWithYear() }}</span>
                        <span class="block truncate text-sm text-ink-muted">№ {{ $offer->number }}@if ($offer->mileage) · {{ number_format($offer->mileage, 0, '', ' ') }} км @endif</span>
                    </span>
                    @if (auth()->user()?->role->canSeePrices() && $offer->asking_price)<span class="nums shrink-0 text-sm">{{ number_format($offer->asking_price, 0, '', ' ') }} ₽</span>@endif
                </a>
            @endforeach
        </div>
    @elseif (mb_strlen($q) >= 2)
        <p class="px-1 py-6 text-center text-sm text-ink-muted">Ничего не нашлось</p>
    @endif
</turbo-frame>
