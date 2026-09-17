{{-- Метки на кадре: новый, заканчивается, приём закрыт, таймер, где машина; у сотрудника — номер и состояние. --}}
@props(['offer', 'admin' => false])
@php
    $gallery = $offer->isGallery();
    // Срок приёма — тем, кто подтверждает ценой; покупателю метки приёма ни о чём.
    $bids = $admin || (auth()->user()?->role->canBid() ?? false);
    $left = $gallery || !$bids ? null : $offer->secondsLeft();
@endphp
@if ($admin)
    <span class="mark mark-glass nums">№ {{ $offer->number }}</span>
    <span class="mark {{ match ($offer->state->tone()) { 'open' => 'mark-accent', 'urgent' => 'mark-urgent', 'danger' => 'mark-danger', default => 'mark-glass' } }}">{{ $offer->state->label() }}</span>
@endif
@unless ($gallery)
    @if (!$admin && $offer->isFresh())<span class="mark mark-accent">Новый</span>@endif
    @if ($offer->isEndingSoon() && !$admin && $bids)<span class="mark mark-urgent">Заканчивается</span>@endif
    @if (!$offer->bidsOpen() && $offer->state !== \App\Offers\OfferState::Draft && !$admin && $bids)<span class="mark mark-glass">Приём закрыт</span>@endif
    @if ($left !== null && $left > 0)<span class="mark {{ $admin && $offer->isEndingSoon() ? 'mark-urgent' : 'mark-glass' }} nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт"></span>@endif
@endunless
@if ($offer->car_place)<x-ui.place class="mark mark-glass">{{ $offer->car_place->label() }}</x-ui.place>@endif
