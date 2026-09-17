{{-- Окошко строки таблицы предложений и галереи (фрейм peek). --}}
@php
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    $left = $gallery ? null : $offer->secondsLeft();
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="'/offers/'.$n" :title="$offer->titleWithYear()" :photo="$offer->mainPhoto()" :facts="array_slice($offer->facts(), 1)">
        <x-slot:marks>
            <span class="tag nums">№ {{ $n }}</span>
            <x-ui.pill :tone="$offer->state->tone()" class="!min-h-0 !py-1 text-xs">{{ $offer->state->label() }}</x-ui.pill>
            @if ($left !== null && $left > 0)<span class="tag nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт"></span>@endif
            @if ($offer->car_place)<span class="tag">{{ $offer->car_place->label() }}</span>@endif
            @if ($offer->settlement)<span class="tag">{{ $offer->settlement->name }}</span>@endif
            @if ($offer->vin)<span class="tag nums">{{ $offer->vin }}</span>@endif
            @if ($gallery && $offer->interests_count)<span class="tag text-accent-text nums">{{ $offer->interests_count }} {{ \App\Support\Plural::of($offer->interests_count, ['интерес', 'интереса', 'интересов']) }}</span>@endif
            @if (!$gallery && $offer->active_bids_count)<span class="tag text-urgent nums">{{ $offer->active_bids_count }} {{ \App\Support\Plural::of($offer->active_bids_count, ['подтверждение', 'подтверждения', 'подтверждений']) }}@if ($offer->top_bid) до {{ \App\Support\Money::rub($offer->top_bid) }}@endif</span>@endif
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
