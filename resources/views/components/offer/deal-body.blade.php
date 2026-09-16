{{-- Начинка блока цены: цена, срок приёма, предложение цены или интерес, чат. --}}
@props(['offer', 'myBid' => null, 'myInterest' => null, 'chat' => null, 'canChat' => false, 'inSheet' => false])
@php
    $user = auth()->user();
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $prices = $price->visible;
    // Срок приёма — тем, кто подтверждает; покупателю таймер ни о чём.
    $left = $gallery || !($user?->role->canBid() ?? false) ? null : $offer->secondsLeft();
    $canBid = ($user?->role->canBid() ?? false) && $offer->bidsOpen();
    $wantsInterest = ($user?->role->canInterest() ?? false) && $offer->state->acceptsInterest();
    $manager = $user?->isBuyer() ? $user->manager : null;
@endphp
@if ($inSheet)
    <div class="mb-2 flex justify-end lg:hidden"><button type="button" class="sheet-close" data-action="sheet#close" aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button></div>
@endif

@if ($price->shown())
    <div class="nums text-[32px] leading-tight" data-controller="fit">@if ($price->withFrom())<span class="text-[.7em] text-ink-muted">{{ $price::money($price->from) }}&nbsp;→</span> @endif{{ $price::money($price->to) }}&nbsp;₽@if ($price->vat) <span class="text-[.45em] font-normal text-ink-muted">с НДС</span>@endif</div>
    @if ($price->declared)<div class="mt-2"><span class="tag nums">заявлена {{ $price::money($price->declared) }}</span></div>@endif
@elseif ($gallery)
    <div class="text-lg text-accent-text">Скоро в продаже</div>
@endif

@if ($left !== null && $left > 0)
    <div class="mt-5 flex items-baseline justify-between gap-3 rounded-(--radius-l) bg-surface-2 px-4 py-3" data-closes-with-timer>
        <span class="text-sm text-ink-muted">Приём закрывается</span>
        <span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="закрыт"></span>
    </div>
    <div class="mt-5 rounded-(--radius-l) bg-surface-2 px-4 py-3 text-sm text-ink-muted" hidden data-shows-when-closed>Приём подтверждений закрыт</div>
@elseif (!$gallery && ($user?->role->canBid() ?? false) && !$offer->bidsOpen() && $offer->state !== \App\Offers\OfferState::Draft)
    <div class="mt-5 rounded-(--radius-l) bg-surface-2 px-4 py-3 text-sm text-ink-muted">Приём подтверждений закрыт</div>
@endif

@guest
    <a href="/vhod?intended={{ urlencode(request()->getRequestUri()) }}" class="btn btn-accent mt-6 w-full">{{ $gallery ? 'Войти' : 'Войти, чтобы узнать цену' }}</a>
@else
    @if ($canBid && $prices)
        <div data-closes-with-timer><x-offer.bid-form :offer="$offer" :my-bid="$myBid"/></div>
    @elseif ($wantsInterest)
        @if ($myInterest)
            <p class="flash flash-accent mt-6">{{ $gallery ? 'Сообщим, когда откроется приём' : ($myInterest->state === \App\Offers\InterestState::New ? ($manager ? $manager->shortName().' свяжется с вами' : 'Менеджер свяжется с Вами') : 'С Вами связались') }}</p>
            @if ($myInterest->comment)<p class="mt-2 text-sm text-ink-muted">{{ $myInterest->comment }}</p>@endif
            <form method="post" action="/offers/{{ $offer->number }}/interes" class="mt-3" data-turbo-confirm="Снять интерес?" data-turbo-confirm-label="Снять" data-turbo-confirm-text="{{ $manager?->shortName() ?? 'Менеджер' }} увидит, что вы передумали.">
                @csrf @method('delete')
                <button type="submit" class="btn btn-s btn-ghost w-full">Передумал</button>
            </form>
        @else
            <form method="post" action="/offers/{{ $offer->number }}/interes" class="mt-6 flex flex-col gap-3">
                @csrf
                <textarea name="comment" rows="2" class="field-input !min-h-0 text-sm" placeholder="{{ $user->isBuyer() ? 'Пара слов менеджеру — необязательно' : 'Что важно уточнить' }}">{{ old('comment') }}</textarea>
                <button type="submit" class="btn btn-accent w-full">{{ $gallery || $user->isBuyer() ? 'Проявить интерес' : 'Узнать цену' }}</button>
            </form>
        @endif
        @if ($manager)
            {{-- Покупатель видит своего менеджера: строка-контакт, с телефоном — вся строка звонит. --}}
            @php $tag = $manager->phone ? 'a' : 'div'; @endphp
            <{{ $tag }} @if ($manager->phone) href="tel:+{{ $manager->phone }}" @endif class="row mt-5 bg-surface-2">
                <x-ui.avatar :user="$manager" :size="40"/>
                <span class="min-w-0 flex-1">
                    <span class="block truncate">{{ $manager->name }}</span>
                    @if ($manager->phone)<span class="row-sub"><span class="nums">{{ $manager->phoneFormatted() }}</span></span>@endif
                </span>
                @if ($manager->phone)<span class="btn btn-s btn-quiet btn-round"><x-ui.icon name="phone" class="size-5"/></span>@endif
            </{{ $tag }}>
        @endif
    @endif

    @if ($canChat)
        <button type="button" class="btn btn-quiet mt-3 w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="chat:open"><x-ui.icon name="chat" class="size-5"/> Написать в чат@if ($chat?->unread_for_user) <span class="badge">{{ $chat->unread_for_user }}</span>@endif</button>
    @endif
@endguest
