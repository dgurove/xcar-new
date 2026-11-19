{{-- Полоса действий на телефоне: одна лаймовая кнопка и кружок чата. Нет действия — нет полосы. --}}
@props(['offer', 'myBid' => null, 'myInterest' => null, 'chat' => null, 'canChat' => false])
@php
    $user = auth()->user();
    $gallery = $offer->isGallery();
    $prices = !$gallery && ($user?->canSeePrices() ?? false);
    $canBid = ($user?->bidsOnSite() ?? false) && ($offer->bidsOpen() || $offer->canBidInDeal($user));
    $interest = ($user?->canInterestIn($offer) ?? false) && !$myInterest;
    // На оценке (11.10.2026): «Оценить» / «Ваша оценка N» открывают тот же лист цены.
    $estimate = $offer->state === \App\Offers\OfferState::Draft && $user?->isManager() ? \App\Offers\Estimate::openFor($offer, $user) : null;
    // Контрпредложение ждёт ответа (26.10.2026) — главная кнопка ведёт к нему и после конца приёма: ответ — в том же листе.
    $counter = $myBid?->hasPendingCounter() ? 'Контрпредложение '.\App\Support\Money::nums($myBid->counter_amount) : null;
    // Блок цены на странице, а не в шторке — его кнопки и «Написать» уже на экране: плашка их не повторяет (19.11.2026, владелец:
    // «не должно быть двух плашек действий»). «Открыть покупателям» — чипом «Открыть…» в блоке «Покупатели» (`site/offers/show`).
    $inSheet = \App\Offers\DealPlacement::inSheet($offer, $user, $myInterest, $myBid);
    $canChat = $canChat && $inSheet;
    $label = ! $inSheet ? null : $counter ?? ($estimate ? ($estimate->isGiven() ? ($estimate->isCorrection() ? 'Ваш ответ' : 'Ваша оценка '.\App\Support\Money::nums($estimate->amount)) : ($estimate->isCorrection() ? 'Проверить цену' : 'Оценить')) : ($canBid && $prices ? ($myBid ? 'Изменить предложение' : 'Подтвердить предложение') : ($interest ? ($gallery && $user->isBuyer() ? 'Сообщить о продаже' : ($gallery || $user->isBuyer() ? 'Проявить интерес' : 'Узнать цену')) : null)));
@endphp
@if ($label)
    <x-ui.action-bar class="lg:hidden">
        @if (!$user)
            <a href="/login?intended={{ urlencode(request()->getRequestUri()) }}" class="btn btn-accent min-w-0 flex-1">Войти</a>
        @elseif ($label)
            <button type="button" class="btn {{ $estimate?->isGiven() ? 'btn-quiet' : 'btn-accent' }} min-w-0 flex-1 nums" data-controller="emit" data-action="emit#send" data-emit-event-param="deal:open" @if ($canBid && $prices && ! $counter) data-closes-with-timer @endif>{{ $label }}</button>
        @endif
        @if ($canChat)
            <a href="{{ \App\Chats\Chat::offerPath($offer->number) }}" class="btn btn-quiet btn-round btn-lg relative shrink-0" data-controller="emit" data-action="emit#send" data-emit-event-param="chat:open" data-emit-wide-param="1024" aria-label="Чат"><x-ui.icon name="chat" class="size-6"/>@if ($chat?->unread_for_user)<span class="badge absolute -right-0.5 -top-0.5">{{ $chat->unread_for_user }}</span>@endif</a>
        @endif
    </x-ui.action-bar>
@endif
