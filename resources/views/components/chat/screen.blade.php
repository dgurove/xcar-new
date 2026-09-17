{{-- Экран чата: бар собеседника (на телефоне — вместо шапки: «‹», аватар, имя, «в сети» / «печатает»,
     позвонить), полоска ТС, лента во всю высоту, поле снизу. Общий для кабинета и CRM.
     other — человек на том конце (аватар, телефон, «в сети»); без него — площадка.
     chat может не быть (экран «написать» со страницы ТС): тогда offer — сама ТС, лента пуста,
     первое сообщение заводит чат. readonly — сотрудник читает чужую переписку. --}}
@props(['chat', 'messages', 'user', 'name', 'other' => null, 'offer' => null, 'back' => '/account/chats', 'open' => null, 'firstUnread' => 0, 'more' => false, 'readonly' => false])
@php
    $offer ??= $chat?->offer;
    $price = $offer ? \App\Offers\PriceView::for($offer, $user) : null;
    $seen = $other?->seenLabel();
@endphp
<div class="chat-page">
    <div class="chat-head">
        <a href="{{ $back }}" class="chat-back header-btn" aria-label="Назад" data-controller="back" data-action="back#go" data-turbo-action="replace"><x-ui.icon name="chevron-left" class="size-5"/></a>
        <x-chat.avatar :user="$other" :size="36" :online="$seen === 'в сети'"/>
        <div class="min-w-0 flex-1">
            <div class="truncate font-medium leading-tight">{{ $name }}</div>
            <div class="truncate text-[13px] leading-tight text-ink-muted" data-chat-status>{{ $seen }}</div>
        </div>
        @if ($readonly && $chat?->manager)<x-ui.person :user="$chat->manager" class="shrink-0"/>@endif
        @if ($other?->phone)<a href="tel:+{{ $other->phone }}" class="btn btn-ghost btn-round" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
    </div>
    @if ($offer)
        <a href="/offers/{{ $offer->number }}" class="chat-car" target="_top">
            <div class="chat-car-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="40px"/></div>
            <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $offer->titleWithYear() }}</span>
            @if ($price?->shown())<span class="nums shrink-0 text-sm text-ink-muted">{{ $price::money($price->to) }} ₽</span>@endif
            <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
        </a>
    @endif
    <x-chat.box :chat="$chat" :messages="$messages" :user="$user" :first-unread="$firstUnread" :more="$more" :readonly="$readonly" :open="$open ?? ($chat || !$offer ? null : '/offers/'.$offer->number.'/chat')" screen/>
</div>
