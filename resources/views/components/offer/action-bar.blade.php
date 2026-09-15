{{-- Полоса действий на телефоне: одна лаймовая кнопка и кружок чата. Нет действия — нет полосы. --}}
@props(['offer', 'myBid' => null, 'myInterest' => null, 'chat' => null, 'canChat' => false])
@php
    $user = auth()->user();
    $gallery = $offer->isGallery();
    $prices = !$gallery && ($user?->role->canSeePrices() ?? false);
    $canBid = ($user?->role->canBid() ?? false) && $offer->bidsOpen();
    $interest = ($user?->role->canInterest() ?? false) && $offer->state->acceptsInterest() && !$myInterest;
    $label = $canBid && $prices ? ($myBid ? 'Изменить предложение' : 'Подтвердить предложение') : ($interest ? ($gallery || $user->isBuyer() ? 'Проявить интерес' : 'Узнать цену') : null);
    $canShow = $user?->isManager() && !$gallery && $offer->state->isPublic();
@endphp
@if ($label || $canShow)
    <x-ui.action-bar class="lg:hidden">
        @if (!$user)
            <a href="/vhod?intended={{ urlencode(request()->getRequestUri()) }}" class="btn btn-accent min-w-0 flex-1">Войти</a>
        @elseif ($label)
            <button type="button" class="btn btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="deal:open" @if ($canBid && $prices) data-closes-with-timer @endif>{{ $label }}</button>
        @endif
        @if ($canShow)
            <button type="button" class="btn {{ $label ? 'btn-quiet btn-round btn-lg' : 'btn-accent min-w-0 flex-1' }}" data-controller="emit" data-action="emit#send" data-emit-event-param="show:open" aria-label="Показать покупателям"><x-ui.icon name="users" class="size-6"/>@unless ($label) Показать покупателям @endunless</button>
        @endif
        @if ($canChat)
            <button type="button" class="btn btn-quiet btn-round btn-lg relative" data-controller="emit" data-action="emit#send" data-emit-event-param="chat:open" aria-label="Чат"><x-ui.icon name="chat" class="size-6"/>@if ($chat?->unread_for_user)<span class="badge absolute -right-0.5 -top-0.5">{{ $chat->unread_for_user }}</span>@endif</button>
        @endif
    </x-ui.action-bar>
@endif
