{{-- Экран чата: полоса собеседника (аватар, имя, «в сети» / «печатает…», позвонить), плашка ТС,
     лента во всю высоту с полем внизу. Общий для кабинета и CRM; readonly — только читать.
     other — человек на том конце (для аватара, телефона и «в сети»); без него — площадка. --}}
@props(['chat', 'messages', 'user', 'name', 'other' => null, 'firstUnread' => 0, 'more' => false, 'readonly' => false])
@php $price = $chat->offer ? \App\Offers\PriceView::for($chat->offer, $user) : null; @endphp
<div class="chat-page">
    <div class="chat-head">
        @if ($other)<x-ui.avatar :user="$other" :size="40"/>@else<span class="avatar" style="width:40px;height:40px"><x-ui.icon name="chat" class="size-5"/></span>@endif
        <div class="min-w-0 flex-1">
            <div class="truncate font-medium">{{ $name }}</div>
            <div class="truncate text-sm text-ink-muted" data-chat-status>{{ $other?->seenLabel() }}</div>
        </div>
        @if ($readonly && $chat->manager)<x-ui.person :user="$chat->manager" class="hidden sm:inline-flex"/>@endif
        @if ($other?->phone)<a href="tel:+{{ $other->phone }}" class="btn btn-ghost px-2" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
    </div>
    @if ($chat->offer)
        <a href="/offers/{{ $chat->offer->number }}" class="chat-car" target="_top">
            <div class="row-photo !h-10 !w-14"><x-offer.photo :media="$chat->offer->mainPhoto()" sizes="56px"/></div>
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-2"><span class="truncate text-sm font-medium">{{ $chat->offer->titleWithYear() }}</span><span class="nums shrink-0 text-xs text-ink-dim">№ {{ $chat->offer->number }}</span></div>
                @if ($price?->shown())<div class="nums text-sm text-ink-muted">{{ $price::money($price->to) }} ₽</div>@endif
            </div>
            <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
        </a>
    @endif
    <x-chat.box :chat="$chat" :messages="$messages" :user="$user" :first-unread="$firstUnread" :more="$more" :readonly="$readonly" screen/>
</div>
