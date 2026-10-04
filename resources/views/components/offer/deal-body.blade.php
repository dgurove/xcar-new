{{-- Начинка блока цены: цена, срок приёма, предложение цены или интерес, чат. --}}
@props(['offer', 'myBid' => null, 'myInterest' => null, 'chat' => null, 'canChat' => false, 'inSheet' => false])
@php
    $user = auth()->user();
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $prices = $price->visible;
    // Срок приёма — тем, кто подтверждает; покупателю таймер ни о чём.
    $left = $gallery || !($user?->canBid() ?? false) ? null : $offer->secondsLeft();
    $canBid = ($user?->canBid() ?? false) && $offer->bidsOpen();
    $wantsInterest = ($user?->canInterest() ?? false) && $offer->state->acceptsInterest();
    $manager = $user?->isBuyer() ? $user->manager : null;
@endphp
@if ($inSheet)
    <div class="mb-2 flex justify-end lg:hidden"><button type="button" class="sheet-close" data-action="sheet#close" aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button></div>
@endif

@if ($price->shown())
    <div class="nums text-[32px] font-bold leading-tight" data-controller="fit">@if ($price->withFrom())<span class="text-[.7em] text-ink-muted">{{ $price::money($price->from) }}&nbsp;→</span> @endif{{ $price::money($price->to) }}&nbsp;₽@if ($price->vat) <span class="text-[.45em] font-normal text-ink-muted">с НДС</span>@endif</div>
    @if ($price->declared)<div class="mt-2"><span class="tag nums">заявленная {{ $price::money($price->declared) }}</span></div>@endif
@elseif ($gallery)
    <div class="text-lg text-accent-text">Скоро в продаже</div>
@endif

{{-- Срок приёма — строкой под ценой, без своей плашки внутри карточки. --}}
@if ($left !== null && $left > 0)
    <p class="mt-3 flex items-baseline justify-between gap-3 text-sm text-ink-muted" data-closes-with-timer>
        Приём закрывается
        <span class="nums text-base font-semibold {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-ink' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="закрыт" data-timer-coarse-value="true" data-timer-word-value="через">{{ \App\Support\Ago::left($offer->bids_close_at, 'через') }}</span>
    </p>
    <p class="mt-3 text-sm text-ink-muted" hidden data-shows-when-closed>Приём подтверждений закрыт</p>
@elseif (!$gallery && ($user?->canBid() ?? false) && !$offer->bidsOpen() && $offer->state !== \App\Offers\OfferState::Draft)
    <p class="mt-3 text-sm text-ink-muted">Приём подтверждений закрыт</p>
@endif
@if ($myBid && ! $canBid)<x-offer.my-bid :offer="$offer" :bid="$myBid" class="mt-3"/>@endif

@guest
    <a href="/login?intended={{ urlencode(request()->getRequestUri()) }}" class="btn btn-accent mt-6 w-full">{{ $gallery ? 'Войти' : 'Войти, чтобы узнать цену' }}</a>
@else
    @if ($canBid && $prices)
        <div data-closes-with-timer><x-offer.bid-form :offer="$offer" :my-bid="$myBid"/></div>
    @elseif ($wantsInterest)
        @if ($myInterest)
            <p class="flash flash-accent mt-6">{{ $gallery ? 'Сообщим, когда откроется приём' : ($myInterest->state === \App\Offers\InterestState::New ? ($manager ? $manager->shortName().' свяжется с вами' : 'Менеджер свяжется с Вами') : 'С Вами связались') }}</p>
            @if ($myInterest->comment)<p class="mt-2 text-sm text-ink-muted">{{ $myInterest->comment }}</p>@endif
            <form method="post" action="/offers/{{ $offer->number }}/interest" class="mt-3" data-turbo-confirm="Снять интерес?" data-turbo-confirm-label="Снять" data-turbo-confirm-text="{{ $manager?->shortName() ?? 'Менеджер' }} увидит, что вы передумали">
                @csrf @method('delete')
                <button type="submit" class="btn btn-s btn-ghost w-full">Передумал</button>
            </form>
        @else
            <form method="post" action="/offers/{{ $offer->number }}/interest" class="mt-6 flex flex-col gap-3">
                @csrf
                <textarea name="comment" rows="2" class="field-input !min-h-0 sm:text-sm" placeholder="{{ $user->isBuyer() ? 'Пара слов менеджеру' : 'Что важно уточнить' }}">{{ old('comment') }}</textarea>
                <button type="submit" class="btn btn-accent w-full">{{ $gallery || $user->isBuyer() ? 'Проявить интерес' : 'Узнать цену' }}</button>
            </form>
        @endif
        @if ($manager)
            {{-- Покупатель видит своего менеджера: строка-контакт без плашки, с телефоном — вся строка звонит. --}}
            <x-ui.person-row :user="$manager" class="mt-5"/>
        @endif
    @endif

    @if ($canChat)
        <a href="/account/chats/offer/{{ $offer->number }}" class="btn btn-quiet mt-3 w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="chat:open" data-emit-wide-param="1024"><x-ui.icon name="chat" class="size-5"/> {{ $manager ? 'Написать '.$manager->shortName() : 'Написать в чат' }}@if ($chat?->unread_for_user) <span class="badge">{{ $chat->unread_for_user }}</span>@endif</a>
    @endif
@endguest
