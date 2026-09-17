{{-- Окошко строки таблицы предложений на сайте (фрейм peek) — решение без страницы:
     лента фото, метки, цена как видит человек, факты; действия — подтвердить ценой
     (быстрые кнопки полной цены и скидок, поле, комментарий; своя цена и «Отозвать»),
     проявить интерес / узнать цену («Передумал» после), избранное, чат и «Показать
     покупателям» — ссылками на страницу; ниже описание. Формы отвечают в окошко
     (PeekBack), строка — свежей из row. --}}
@php
    use App\Offers\{OfferState, InterestState};
    $user = auth()->user();
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $bids = $user?->role->canBid() ?? false;
    $left = $gallery || !$bids ? null : $offer->secondsLeft();
    $href = $context?->offerUrl($offer) ?? "/offers/{$n}";
    $canBid = $bids && $offer->bidsOpen() && $price->visible;
    $wantsInterest = ($user?->role->canInterest() ?? false) && $offer->state->acceptsInterest();
    $manager = $user?->isBuyer() ? $user->manager : null;
    $asking = (int) $offer->asking_price;
    $min = (int) ($offer->minBid() ?? 0);
    $discounts = array_filter([2, 5], fn ($p) => round($asking * (1 - $p / 100) / 1000) * 1000 >= $min);
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$href" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :facts="array_slice($offer->facts(), 1)" :action="false">
        <x-slot:marks>
            @if (!$gallery && $offer->isFresh())<x-ui.pill tone="open" class="!min-h-0 !py-1 text-xs">Новый</x-ui.pill>@endif
            @if ($left !== null && $left > 0)<span class="tag nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт"></span>@elseif ($bids && !$gallery && !$offer->bidsOpen())<span class="tag">Приём закрыт</span>@endif
            @if ($offer->car_place)<x-ui.place class="tag">{{ $offer->car_place->label() }}</x-ui.place>@endif
            @if ($offer->settlement)<x-ui.place class="tag">{{ $offer->settlement->name }}</x-ui.place>@endif
            <x-ui.vin-code :vin="$offer->vinMasked()" :copy="$offer->show_vin" class="tag"/>
            <x-offer.tags :offer="$offer" :facts="false"/>
        </x-slot:marks>
        <x-slot:aside>
            @if ($price->shown())
                <span class="nums block whitespace-nowrap text-[17px]">@if ($price->withFrom())<span class="text-ink-muted">{{ $price::money($price->from) }}&nbsp;→</span> @endif<span class="font-bold">{{ $price::money($price->to) }}&nbsp;₽</span>@if ($price->vat) <span class="text-xs font-normal text-ink-muted">с НДС</span>@endif</span>
                @if ($price->declared)<span class="text-xs text-ink-dim nums">заявлена {{ $price::money($price->declared) }}</span>@endif
            @elseif ($gallery)
                <span class="text-sm text-accent-text">Скоро в продаже</span>
            @endif
        </x-slot:aside>
        <x-slot:actions>
            @if ($canBid)
                <form method="post" action="/offers/{{ $n }}/confirm" class="flex w-full flex-col gap-2" data-controller="bid" data-bid-asking-value="{{ $asking }}">
                    @csrf
                    <div class="flex gap-2">
                        <input type="hidden" name="amount" data-bid-target="amount" value="{{ old('amount', $myBid?->amount) }}">
                        <input type="text" inputmode="numeric" required autocomplete="off" enterkeyhint="go" class="field-input field-s nums min-w-0 flex-1" placeholder="Цена, ₽" aria-label="Цена, ₽" data-bid-target="display" data-action="input->bid#input" value="{{ old('amount', $myBid?->amount ? \App\Support\Money::nums($myBid->amount) : '') }}" data-peek-focus>
                        <button type="submit" class="btn btn-s btn-accent shrink-0" data-bid-target="submit">{{ $myBid ? 'Изменить' : 'Подтвердить' }}</button>
                    </div>
                    @error('amount')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap items-center gap-1.5">
                        @if ($asking)
                            <button type="button" class="pill pill-plain nums" data-action="bid#set" data-bid-amount-param="{{ $asking }}">{{ \App\Support\Money::nums($asking) }}</button>
                            @foreach ($discounts as $percent)<button type="button" class="pill pill-plain nums" data-action="bid#discount" data-bid-percent-param="{{ $percent }}">−{{ $percent }}%</button>@endforeach
                        @endif
                        <input name="comment" class="field-input field-s min-w-0 flex-1 text-sm" placeholder="Комментарий" value="{{ old('comment', $myBid?->comment) }}">
                    </div>
                </form>
                @if ($myBid)
                    <span class="text-sm text-ink-muted">Ваша цена <span class="nums font-semibold text-ink">{{ \App\Support\Money::rub($myBid->amount) }}</span> — {{ mb_strtolower($myBid->state->label()) }}</span>
                    <form method="post" action="/confirmations/{{ $myBid->id }}/withdraw" class="contents">@csrf<button type="submit" class="pill pill-plain">Отозвать</button></form>
                @endif
            @elseif ($wantsInterest)
                @if ($myInterest)
                    <span class="flash flash-accent w-full text-sm">{{ $gallery ? 'Сообщим, когда откроется приём' : ($myInterest->state === InterestState::New ? ($manager ? $manager->shortName().' свяжется с вами' : 'Менеджер свяжется с Вами') : 'С Вами связались') }}</span>
                    <form method="post" action="/offers/{{ $n }}/interest" class="contents" data-turbo-confirm="Снять интерес?" data-turbo-confirm-label="Снять" data-turbo-confirm-text="{{ $manager?->shortName() ?? 'Менеджер' }} увидит, что вы передумали.">
                        @csrf @method('delete')
                        <button type="submit" class="pill pill-plain">Передумал</button>
                    </form>
                @else
                    <form method="post" action="/offers/{{ $n }}/interest" class="flex w-full gap-2">
                        @csrf
                        <input name="comment" class="field-input field-s min-w-0 flex-1 text-sm" placeholder="{{ $user->isBuyer() ? 'Пара слов менеджеру' : 'Что важно уточнить' }}" value="{{ old('comment') }}">
                        <button type="submit" class="btn btn-s btn-accent shrink-0">{{ $gallery || $user->isBuyer() ? 'Проявить интерес' : 'Узнать цену' }}</button>
                    </form>
                @endif
            @elseif ($bids && !$gallery && !$offer->bidsOpen() && $offer->state !== OfferState::Draft)
                <span class="text-sm text-ink-muted">Приём подтверждений закрыт</span>
            @endif
            @auth<x-offer.favorite :offer="$offer" variant="compact"/>@endauth
            @if ($canChat)<a href="{{ $href }}{{ str_contains($href, '?') ? '&' : '?' }}chat=1" class="pill pill-plain"><x-ui.icon name="chat" class="size-4"/> Чат@if ($chat?->unread_for_user) <span class="badge">{{ $chat->unread_for_user }}</span>@endif</a>@endif
            @if ($user?->isManager() && $offer->state->isPublic())<a href="{{ $href }}" class="pill pill-plain"><x-ui.icon name="users" class="size-4"/> Показать покупателям</a>@endif
            @if ($manager?->phone)<a href="tel:+{{ $manager->phone }}" class="pill pill-plain"><x-ui.icon name="phone" class="size-4"/> {{ $manager->shortName() }}</a>@endif
        </x-slot:actions>
        @if ($offer->description)<p class="mt-3 whitespace-pre-line text-sm text-ink-muted">{{ $offer->description }}</p>@endif
        <x-slot:row><x-offer.site-table-row :offer="$offer" :context="$context"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
