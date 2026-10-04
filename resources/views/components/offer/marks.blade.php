{{-- Метки на кадре витрины: новый, заканчивается, приём закрыт, срок приёма, где ТС. --}}
@props(['offer'])
@php
    $gallery = $offer->isGallery();
    // Срок приёма — тем, кто подтверждает ценой; покупателю метки приёма ни о чём.
    $bids = auth()->user()?->role->canBid() ?? false;
    $left = $gallery || !$bids ? null : $offer->secondsLeft();
    // Срок словами; рядом с «Заканчивается» — голым числом: слово уже сказано, и на кадре плитки две метки иначе не влезают.
    $word = $offer->isEndingSoon() ? '' : 'осталось';
@endphp
@unless ($gallery)
    @if ($offer->isFresh())<span class="mark mark-accent">Новый</span>@endif
    @if ($offer->isEndingSoon() && $bids)<span class="mark mark-urgent">Заканчивается</span>@endif
    @if (!$offer->bidsOpen() && $offer->state !== \App\Offers\OfferState::Draft && $bids)<span class="mark mark-glass">Приём закрыт</span>@endif
    @if ($left !== null && $left > 0)<span class="mark mark-glass nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true" data-timer-word-value="{{ $word }}">{{ \App\Support\Ago::left($offer->bids_close_at, $word) }}</span>@endif
@endunless
@if ($offer->car_place)<x-ui.place class="mark mark-glass">{{ $offer->car_place->label() }}</x-ui.place>@endif
