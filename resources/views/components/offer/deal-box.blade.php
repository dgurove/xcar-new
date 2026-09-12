{{-- Блок цены: от 1024 — карточка в потоке, на телефоне — шторка, которую
     открывает полоса действий (deal:open). Когда действия нет — просто карточка. --}}
@props(['offer', 'myBid' => null, 'myInterest' => null, 'chat' => null, 'canChat' => false])
@php
    $user = auth()->user();
    $gallery = $offer->isGallery();
    $prices = !$gallery && ($user?->role->canSeePrices() ?? false);
    $asSheet = !$user
        || (($user->role->canBid() ?? false) && $offer->bidsOpen() && $prices)
        || (!$user->isStaff() && ($gallery || !$prices) && $offer->state->acceptsInterest() && !$myInterest);
@endphp
@if ($asSheet)
    <div data-controller="sheet" data-sheet-inflow-value="(min-width: 1024px)" data-action="deal:open@window->sheet#open" class="contents">
        <dialog id="deal" class="sheet sheet--inflow" data-sheet-target="dialog" data-action="click->sheet#backdrop" aria-label="Цена за предложение" @if ($errors->any()) data-sheet-open-value="true" @endif>
            <x-offer.deal-body :offer="$offer" :my-bid="$myBid" :my-interest="$myInterest" :chat="$chat" :can-chat="$canChat" in-sheet/>
        </dialog>
    </div>
@else
    <div id="deal" class="box"><x-offer.deal-body :offer="$offer" :my-bid="$myBid" :my-interest="$myInterest" :chat="$chat" :can-chat="$canChat"/></div>
@endif
