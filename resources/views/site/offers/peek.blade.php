{{-- Окошко строки таблицы предложений на сайте (фрейм peek). --}}
@php
    $user = auth()->user();
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $bids = $user?->role->canBid() ?? false;
    $left = $gallery || !$bids ? null : $offer->secondsLeft();
    $href = $context?->offerUrl($offer) ?? "/offers/{$n}";
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$href" :title="$offer->titleWithYear()" :photo="$offer->mainPhoto()" :facts="array_slice($offer->facts(), 1)" :action="$gallery ? 'Проявить интерес' : ($user?->isBuyer() ? 'Проявить интерес' : ($price->shown() ? 'Открыть' : 'Узнать цену'))">
        <x-slot:marks>
            @if (!$gallery && $offer->isFresh())<x-ui.pill tone="open" class="!min-h-0 !py-1 text-xs">Новый</x-ui.pill>@endif
            @if ($left !== null && $left > 0)<span class="tag nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт"></span>@elseif ($bids && !$gallery && !$offer->bidsOpen())<span class="tag">Приём закрыт</span>@endif
            @if ($offer->car_place)<span class="tag">{{ $offer->car_place->label() }}</span>@endif
            @if ($offer->settlement)<span class="tag">{{ $offer->settlement->name }}</span>@endif
            @if ($offer->vinMasked())<span class="tag nums">{{ $offer->vinMasked() }}</span>@endif
            <x-offer.tags :offer="$offer" :facts="false"/>
        </x-slot:marks>
        <x-slot:aside>
            @if ($price->shown())
                <span class="nums block whitespace-nowrap text-lg">@if ($price->withFrom())<span class="text-ink-muted">{{ $price::money($price->from) }}&nbsp;→</span> @endif<span class="font-bold">{{ $price::money($price->to) }}&nbsp;₽</span></span>
                @if ($price->declared)<span class="text-sm text-ink-dim nums">заявлена {{ $price::money($price->declared) }}</span>@endif
            @elseif ($gallery)
                <span class="text-sm text-accent-text">Скоро в продаже</span>
            @endif
        </x-slot:aside>
    </x-ui.peek>
</turbo-frame>
