{{-- Окошко строки таблицы предложений и галереи (фрейм peek) — работа с предложением
     без ухода на страницу: лента фото, метки, цена, факты; действия — продлить приём
     (+15 мин / +1 ч), состояние меню-popover (те же переходы, что в шторке страницы),
     чипы-ссылки на сделку и чаты; ниже подтверждения (принять/отклонить), интерес
     (связались/закрыть), описание. Формы отвечают в окошко (PeekBack), строка —
     свежей из row (gallery — какой список её показывает). --}}
@php
    use App\Offers\{OfferState, BidState, InterestState};
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    $left = $gallery ? null : $offer->secondsLeft();
    $transitions = collect(OfferState::cases())->filter(fn ($s) => $offer->state->allows($s) && $s !== OfferState::Delivered);
    $bids = $offer->bids->sortBy([fn ($a, $b) => ($a->state === BidState::Active ? 0 : 1) <=> ($b->state === BidState::Active ? 0 : 1), ['amount', 'desc']]);
    $waiting = $offer->bids->where('state', BidState::Active);
    $best = $waiting->sortByDesc('amount')->first();
    $unread = $chats->sum('unread_for_staff');
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="'/offers/'.$n" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :facts="array_slice($offer->facts(), 1)">
        <x-slot:marks>
            <span class="tag nums">№ {{ $n }}</span>
            <x-ui.pill :tone="$offer->state->tone()" class="!min-h-0 !py-1 text-xs">{{ $offer->state->label() }}</x-ui.pill>
            @if ($left !== null && $left > 0)<span class="tag nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт"></span>
            @elseif (!$gallery && $offer->closed())<span class="tag">приём закрыт</span>@endif
            @if ($offer->car_place)<x-ui.place class="tag">{{ $offer->car_place->label() }}</x-ui.place>@endif
            @if ($offer->settlement)<x-ui.place class="tag">{{ $offer->settlement->name }}</x-ui.place>@endif
            <x-ui.vin-code :vin="$offer->vin" class="tag"/>
            @if ($gallery && $offer->interests_count)<span class="tag text-accent-text nums">{{ $offer->interests_count }} {{ \App\Support\Plural::of($offer->interests_count, ['интерес', 'интереса', 'интересов']) }}</span>@endif
        </x-slot:marks>
        <x-slot:aside>
            @if ($price->shown())
                <span class="nums block whitespace-nowrap text-[17px]">@if ($price->withFrom())<span class="text-ink-muted">{{ $price::money($price->from) }}&nbsp;→</span> @endif<span class="font-bold">{{ $price::money($price->to) }}&nbsp;₽</span></span>
                @if ($price->declared)<span class="text-xs text-ink-dim nums">заявлена {{ $price::money($price->declared) }}</span>@endif
            @elseif ($gallery)
                <span class="text-sm text-accent-text">Скоро в продаже</span>
            @endif
        </x-slot:aside>
        <x-slot:actions>
            @if ($offer->state === OfferState::Open && $offer->bids_close_at)
                @foreach ([15 => '+15 мин', 60 => '+1 ч'] as $minutes => $label)
                    <form method="post" action="/offers/{{ $n }}/extend" class="contents">@csrf<input type="hidden" name="minutes" value="{{ $minutes }}"><button class="pill pill-plain nums">{{ $label }}</button></form>
                @endforeach
            @endif
            @if ($transitions->isNotEmpty())
                <div class="contents" data-controller="menu">
                    <button type="button" class="pill pill-plain" data-action="menu#toggle" aria-haspopup="menu" aria-controls="peek-state-{{ $n }}">Состояние <x-ui.icon name="chevron-down" class="size-4"/></button>
                    <div id="peek-state-{{ $n }}" class="menu" popover data-menu-target="list" role="menu">
                        @foreach ($transitions as $next)
                            <form method="post" action="/offers/{{ $n }}/state" @if (in_array($next, [OfferState::Archived, OfferState::Cancelled])) data-turbo-confirm="{{ $next->label() }}?" @endif>
                                @csrf<input type="hidden" name="state" value="{{ $next->value }}">
                                <button class="menu-item w-full {{ $next->tone() === 'danger' || $next === OfferState::Archived ? 'text-danger' : '' }}" role="menuitem" data-action="menu#close">{{ match($next) {
                                    OfferState::Open => 'Опубликовать',
                                    OfferState::Gallery => 'В галерею «скоро»', OfferState::Draft => 'В черновик',
                                    OfferState::Sold => 'В сделку', OfferState::Cancelled => 'Снять с продажи', OfferState::Archived => 'В архив', default => $next->label() } }}</button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @endif
            @if ($offer->deal)<x-ui.pill tone="open" href="/work/deals/{{ $offer->deal->id }}"><x-ui.icon name="deal" class="size-4"/> Сделка</x-ui.pill>@endif
            @if ($chats->isNotEmpty())<x-ui.pill :tone="$unread ? 'urgent' : 'plain'" href="/work/chats?preset=all&q={{ $n }}"><x-ui.icon name="chat" class="size-4"/> {{ $chats->count() === 1 ? 'Чат' : 'Чатов: '.$chats->count() }}@if ($unread) <span class="badge">{{ $unread }}</span>@endif</x-ui.pill>@endif
            @if ($errors->has('state'))<x-ui.flash tone="danger" class="w-full">{{ $errors->first('state') }}</x-ui.flash>@endif
        </x-slot:actions>
        @if ($bids->isNotEmpty())
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($bids as $bid)
                    <div class="box-nested">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="nums whitespace-nowrap font-semibold">{{ \App\Support\Money::rub($bid->amount) }}</span>
                            @if ($bid->is($best) && $waiting->count() > 1)<x-ui.pill tone="soft" class="!min-h-0 !py-1 text-xs">Лучшая</x-ui.pill>
                            @elseif ($bid->state !== BidState::Active)<x-ui.pill :tone="$bid->state === BidState::Accepted ? 'open' : ($bid->state === BidState::Declined ? 'danger' : 'closed')" class="!min-h-0 !py-1 text-xs">{{ $bid->state->label() }}</x-ui.pill>@endif
                            <x-ui.person :user="$bid->user"/>
                            <a href="tel:+{{ $bid->user->phone }}" class="tag nums">{{ $bid->user->phoneFormatted() }}</a>
                            <span class="tag nums">{{ $bid->created_at->translatedFormat('j M, H:i') }}</span>
                        </div>
                        @if ($bid->comment)<div class="mt-1 text-sm">{{ $bid->comment }}</div>@endif
                        @if ($bid->state === BidState::Active)
                            <div class="mt-2 flex items-start gap-2">
                                <details class="min-w-0 flex-1" @if ($errors->has('commission') && old('bid') == $bid->id) open @endif>
                                    <summary class="btn btn-s btn-accent inline-flex cursor-pointer">Принять</summary>
                                    <div class="mt-3"><x-offer.money-form :action="'/confirmations/'.$bid->id.'/accept'" :amount="$bid->amount" :cost="$offer->floor_price" :note="$waiting->count() > 1 ? 'Остальные подтверждения будут отклонены' : null"><input type="hidden" name="bid" value="{{ $bid->id }}"></x-offer.money-form></div>
                                </details>
                                <form method="post" action="/confirmations/{{ $bid->id }}/decline">@csrf<x-ui.button size="sm" variant="ghost">Отклонить</x-ui.button></form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
        @if ($offer->interests->isNotEmpty())
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($offer->interests as $interest)
                    <div class="box-nested">
                        <div class="flex flex-wrap items-center gap-1.5"><x-ui.person :user="$interest->user"/>@if ($interest->user->manager)<span class="text-sm text-ink-muted">покупатель</span><x-ui.person :user="$interest->user->manager"/>@endif @if ($interest->user->phone)<a href="tel:+{{ $interest->user->phone }}" class="tag nums">{{ $interest->user->phoneFormatted() }}</a>@endif<span class="tag">{{ $interest->state->label() }}</span><span class="tag nums">{{ $interest->created_at->translatedFormat('j M, H:i') }}</span></div>
                        @if ($interest->comment)<div class="mt-1 text-sm">{{ $interest->comment }}</div>@endif
                        @if ($interest->state === InterestState::New)
                            <form method="post" action="/interests/{{ $interest->id }}" class="mt-2">@csrf<input type="hidden" name="state" value="contacted"><x-ui.button size="sm" variant="secondary">Связались</x-ui.button></form>
                        @elseif ($interest->state === InterestState::Contacted)
                            <form method="post" action="/interests/{{ $interest->id }}" class="mt-2">@csrf<input type="hidden" name="state" value="closed"><x-ui.button size="sm" variant="ghost">Закрыть</x-ui.button></form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
        @if ($offer->description)<p class="mt-4 whitespace-pre-line text-sm text-ink-muted">{{ $offer->description }}</p>@endif
        <x-slot:row><x-offer.table-row :offer="$offer" :gallery="$list"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
