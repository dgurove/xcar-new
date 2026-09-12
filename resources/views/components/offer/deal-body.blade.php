{{-- Начинка блока цены: цена, срок приёма, предложение цены или интерес, чат. --}}
@props(['offer', 'myBid' => null, 'myInterest' => null, 'chat' => null, 'canChat' => false, 'inSheet' => false])
@php
    $user = auth()->user();
    $gallery = $offer->isGallery();
    $prices = !$gallery && ($user?->role->canSeePrices() ?? false);
    $left = $gallery ? null : $offer->secondsLeft();
    $canBid = ($user?->role->canBid() ?? false) && $offer->bidsOpen();
    $wantsInterest = $user && !$user->isStaff() && ($gallery || !$prices) && $offer->state->acceptsInterest();
@endphp
@if ($inSheet)
    <div class="mb-2 flex justify-end lg:hidden"><button type="button" class="sheet-close" data-action="sheet#close" aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button></div>
@endif

@if ($prices && $offer->asking_price)
    <div class="nums text-[32px] leading-none" data-controller="fit">@if ($user?->isStaff() && $offer->floor_price)<span class="text-[.7em] text-ink-muted">{{ number_format($offer->floor_price, 0, '', ' ') }}</span> → @endif{{ number_format($offer->asking_price, 0, '', ' ') }}&nbsp;₽@if ($offer->prices_include_vat) <span class="text-[.45em] font-normal text-ink-muted">с НДС</span>@endif</div>
@elseif ($gallery)
    <div class="text-lg text-accent-text">Скоро в продаже</div>
@endif

@if ($left !== null && $left > 0)
    <div class="mt-5 flex items-baseline justify-between gap-3 rounded-(--radius-l) bg-surface-2 px-4 py-3">
        <span class="text-sm text-ink-muted">Приём закрывается</span>
        <span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="закрыт"></span>
    </div>
@elseif (!$gallery && !$offer->state->acceptsBids() && $offer->state !== \App\Offers\OfferState::Draft)
    <div class="mt-5 rounded-(--radius-l) bg-surface-2 px-4 py-3 text-sm text-ink-muted">Приём подтверждений закрыт</div>
@endif

@guest
    <a href="/vhod?intended={{ urlencode(request()->getRequestUri()) }}" class="btn btn-accent mt-6 w-full">{{ $gallery ? 'Войти' : 'Войти, чтобы узнать цену' }}</a>
@else
    @if ($canBid && $prices)
        <x-offer.bid-form :offer="$offer" :my-bid="$myBid"/>
    @elseif ($wantsInterest)
        @if ($myInterest)
            <p class="flash flash-accent mt-6">{{ $gallery ? 'Сообщим, когда откроется приём' : ($myInterest->state === \App\Offers\InterestState::New ? 'Менеджер свяжется с Вами' : 'С Вами связались') }}</p>
        @else
            <form method="post" action="/offers/{{ $offer->number }}/interes" class="mt-6 flex flex-col gap-3">
                @csrf
                <textarea name="comment" rows="2" class="field-input !min-h-0 text-sm" placeholder="Что важно уточнить">{{ old('comment') }}</textarea>
                <button type="submit" class="btn btn-accent w-full">{{ $gallery ? 'Проявить интерес' : 'Узнать цену' }}</button>
            </form>
        @endif
    @endif

    @if ($canChat)
        <button type="button" class="btn btn-quiet mt-3 w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="chat:open"><x-ui.icon name="chat" class="size-5"/> Написать в чат@if ($chat?->unread_for_user) <span class="badge">{{ $chat->unread_for_user }}</span>@endif</button>
    @endif
@endguest
