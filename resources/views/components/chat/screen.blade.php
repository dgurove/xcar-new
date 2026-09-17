{{-- Экран чата: шапка с собеседником, плашка ТС, лента и поле. Аватар и имя в шапке ведут к человеку:
     link — страница (менеджеру покупатель, сотруднику карточка в CRM), sheet — шторка-контакт
     (покупателю менеджер или площадка); status — подпись под именем вместо реального seen_at. --}}
@props(['chat', 'messages', 'user', 'name', 'other' => null, 'offer' => null, 'back' => '/account/chats', 'open' => null, 'firstUnread' => 0, 'more' => false, 'readonly' => false, 'link' => null, 'sheet' => false, 'status' => false, 'chats' => null])
@php
    $offer ??= $chat?->offer;
    $price = $offer ? \App\Offers\PriceView::for($offer, $user) : null;
    $seen = $status === false ? $other?->seenLabel() : $status;
    $crm = \App\Support\Surface::current() === \App\Support\Surface::Crm;
@endphp
<div class="chat-page">
    <div class="chat-head" @if ($sheet) data-controller="sheet" @endif>
        <a href="{{ $back }}" class="chat-back header-btn" aria-label="Назад" data-controller="back" data-action="back#go" data-turbo-action="replace"><x-ui.icon name="chevron-left" class="size-5"/></a>
        @if ($link)<a href="{{ $link }}" class="chat-who" data-turbo-action="advance">@elseif ($sheet)<button type="button" class="chat-who" data-action="sheet#open">@else<div class="chat-who">@endif
            <x-chat.avatar :user="$other" :size="36" :online="$seen === 'в сети'"/>
            <div class="min-w-0 flex-1">
                <div class="truncate font-medium leading-tight">{{ $name }}</div>
                <div class="truncate text-[13px] leading-tight text-ink-muted" data-chat-status>{{ $seen }}</div>
            </div>
        @if ($link)</a>@elseif ($sheet)</button>@else</div>@endif
        @if ($readonly && $chat?->manager)
            @if ($crm)<a href="/settings/users/{{ $chat->manager_id }}?chat={{ $chat->id }}" class="chip person shrink-0"><x-ui.avatar :user="$chat->manager" :size="20"/>{{ $chat->manager->shortName() }}</a>@else<x-ui.person :user="$chat->manager" class="shrink-0"/>@endif
        @endif
        @if ($other?->phone)<a href="tel:+{{ $other->phone }}" class="btn btn-ghost btn-round" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
        @if ($sheet)<x-chat.contact :user="$other" :name="$name" :status="$seen" :chats="$chats" :me="$user"/>@endif
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
