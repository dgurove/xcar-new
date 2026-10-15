{{-- Срок приёма под названием карточки витрины: «осталось 2 д 6 ч», у горящего (меньше 12 ч) — оранжевым, у срочного — цветом огня; «Идёт сделка»,
     «Приём закрыт». Тем, кто подтверждает ценой; покупателю срок ни о чём. «Идёт сделка» — только белому списку сделки
     (`seesDeal`); подтвердившему в резерве машина в сделке — просто «Приём закрыт» (15.10.2026). --}}
@props(['offer'])
@php
    $bids = auth()->user()?->bidsOnSite() ?? false;
    $left = $offer->isGallery() || ! $bids ? null : $offer->secondsLeft();
@endphp
@if ($offer->isGallery())
@elseif ($offer->state === \App\Offers\OfferState::Sold && $offer->seesDeal(auth()->user()))<span {{ $attributes->merge(['class' => 'card-when text-urgent']) }}>Идёт сделка</span>
@elseif ($left !== null && $left > 0)<span {{ $attributes->merge(['class' => 'card-when nums '.($offer->isUrgent() ? 'text-fire' : ($offer->isEndingSoon() ? 'text-urgent' : 'text-ink-muted'))]) }}><x-ui.icon name="timer" class="size-3.5"/><span data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true" data-timer-word-value="осталось">{{ \App\Support\Ago::left($offer->bids_close_at) }}</span></span>
@elseif ($bids && ! $offer->bidsOpen() && $offer->state !== \App\Offers\OfferState::Draft)<span {{ $attributes->merge(['class' => 'card-when text-ink-muted']) }}>Приём закрыт</span>
@endif
