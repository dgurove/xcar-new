{{-- Срок приёма под названием карточки витрины: «осталось 2 д 6 ч», у горящего (меньше 12 ч) — оранжевым, у срочного — цветом огня;
     «Приём закрыт». Тем, кто подтверждает ценой; покупателю срок ни о чём. Сделка, что показывают менеджеру (белый список, показ
     всем, резерв до срока), — обычное предложение со сроком `closesAt`: о сделке ни слова (05.11.2026, владелец). --}}
@props(['offer'])
@php
    $bids = auth()->user()?->bidsOnSite() ?? false;
    $left = $offer->isGallery() || ! $bids ? null : $offer->secondsLeft();
@endphp
@if ($offer->isGallery())
@elseif ($left !== null && $left > 0)<span {{ $attributes->merge(['class' => 'card-when nums '.($offer->isUrgent() ? 'text-fire' : ($offer->isEndingSoon() ? 'text-urgent' : 'text-ink-muted'))]) }}><x-ui.icon name="timer" class="size-3.5"/><span data-controller="timer" data-timer-until-value="{{ $offer->closesAt()->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true" data-timer-word-value="осталось">{{ \App\Support\Ago::left($offer->closesAt()) }}</span></span>
@elseif ($bids && ! $offer->openFor(auth()->user()) && $offer->state !== \App\Offers\OfferState::Draft)<span {{ $attributes->merge(['class' => 'card-when text-ink-muted']) }}>Приём закрыт</span>
@endif
