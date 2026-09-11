@props(['offer'])
@php $user = auth()->user(); @endphp
<div class="tile relative" id="offer-{{ $offer->number }}">
    <a href="/offers/{{ $offer->number }}" class="tile-photo block">
        <x-offer.photo :media="$offer->mainPhoto()"/>
        @if ($offer->state === \App\Offers\OfferState::Closed)<span class="absolute left-2 top-2 chip bg-closed-soft text-closed">Приём закрыт</span>@endif
    </a>
    @auth<x-offer.favorite :offer="$offer" class="absolute right-2 top-2"/>@endauth
    <a href="/offers/{{ $offer->number }}" class="tile-body">
        <span class="font-medium">{{ $offer->titleWithYear() }}</span>
        <span class="text-sm text-ink-muted">{{ implode(' · ', array_slice($offer->facts(), 1, 3)) }}</span>
        @if ($offer->settlement)<span class="text-sm text-ink-muted">{{ $offer->settlement->name }}</span>@endif
        <span class="mt-auto flex items-center justify-between pt-1">
            @if ($offer->state === \App\Offers\OfferState::Gallery)
                <span class="text-sm text-accent-text">Скоро в продаже</span>
            @elseif ($user?->role->canSeePrices())
                <x-offer.price :amount="$offer->asking_price"/>
            @else
                <span class="text-sm text-accent-text">Узнать цену</span>
            @endif
            @if ($offer->state === \App\Offers\OfferState::Open && $offer->bids_close_at)
                <span class="text-sm text-ink-muted tabular-nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}"></span>
            @endif
        </span>
    </a>
</div>
