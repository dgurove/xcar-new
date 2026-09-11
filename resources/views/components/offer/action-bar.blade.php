{{-- Полоса действий на телефоне: одна лаймовая кнопка и кружок чата. Нет действия — нет полосы. --}}
@props(['offer', 'myBid' => null, 'chat' => null])
@php
    $user = auth()->user();
    $gallery = $offer->isGallery();
    $prices = !$gallery && ($user?->role->canSeePrices() ?? false);
    $canBid = ($user?->role->canBid() ?? false) && $offer->bidsOpen();
    $interest = !$user?->isStaff() && ($gallery || !$prices) && $offer->state->acceptsInterest();
    $label = $user?->isStaff() ? 'Редактировать' : ($canBid && $prices ? ($myBid ? 'Изменить предложение' : 'Подтвердить предложение') : ($interest ? ($gallery ? 'Проявить интерес' : 'Узнать цену') : null));
@endphp
@if ($label)
    <x-ui.action-bar class="lg:hidden">
        @if ($user?->isStaff())
            <a href="/admin/offers/{{ $offer->number }}" class="btn btn-accent min-w-0 flex-1">{{ $label }}</a>
        @elseif (!$user)
            <a href="/vhod?intended={{ urlencode(request()->getRequestUri()) }}" class="btn btn-accent min-w-0 flex-1">Войти</a>
        @else
            <button type="button" class="btn btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="deal:open">{{ $label }}</button>
        @endif
        @if ($chat)
            <button type="button" class="btn btn-quiet btn-round btn-lg relative" data-controller="emit" data-action="emit#send" data-emit-event-param="chat:open" aria-label="Чат"><x-ui.icon name="chat" class="size-6"/>@if ($chat->unread_for_user)<span class="badge absolute -right-0.5 -top-0.5">{{ $chat->unread_for_user }}</span>@endif</button>
        @endif
    </x-ui.action-bar>
@endif
