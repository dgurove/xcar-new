{{-- Блок цены: от 1024 — карточка в потоке, на телефоне — шторка, которую
     открывает полоса действий (событие deal:open). --}}
@props(['offer', 'myBid' => null, 'myInterest' => null, 'chat' => null, 'chatsCount' => 0])
<div data-controller="sheet" data-sheet-inflow-value="(min-width: 1024px)" data-action="deal:open@window->sheet#open" class="contents">
    <dialog id="deal" class="sheet sheet--inflow" data-sheet-target="dialog" data-action="click->sheet#backdrop" aria-label="Цена за оффер" @if ($errors->any()) data-sheet-open-value="true" @endif>
        <x-offer.deal-body :offer="$offer" :my-bid="$myBid" :my-interest="$myInterest" :chat="$chat" :chats-count="$chatsCount" in-sheet/>
    </dialog>
</div>
