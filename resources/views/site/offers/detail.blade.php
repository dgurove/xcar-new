{{-- Карточка строки таблицы предложений на сайте (фрейм detail) — решение без страницы:
     лента фото, метки, цена как видит человек, факты; действия — подтвердить ценой
     (быстрые кнопки полной цены и скидок, поле, комментарий; своя цена и «Отозвать»),
     проявить интерес / узнать цену («Передумал» после), избранное, чат и «Показать
     покупателям» — ссылками на страницу; ниже описание. Формы отвечают в карточка
     (DetailBack), строка — свежей из row. --}}
@php
    use App\Offers\{OfferState, InterestState};
    $user = auth()->user();
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $bids = $user?->bidsOnSite() ?? false;
    $left = $gallery || !$bids ? null : $offer->secondsLeft();
    $href = $context?->offerUrl($offer) ?? "/offers/{$n}";
    $canBid = $bids && $offer->bidsOpen() && $price->visible;
    $wantsInterest = $user?->canInterestIn($offer) ?? false;
    $manager = $user?->isBuyer() ? $user->manager : null;
    $asking = (int) $offer->asking_price;
    $discounts = $offer->bidDiscounts(auth()->user());
@endphp
<x-ui.detail>
    <x-ui.row-card :href="$href" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :facts="array_slice($offer->facts(), 1)" :action="false">
        {{-- Коды и метки — чипами, где и сколько осталось — фразой (`line`, как в CRM; владелец 10.10.2026: «одно к машине
             относится, другое к фактам»). --}}
        <x-slot:badge>@if (!$gallery && $offer->isFresh())<x-ui.state tone="fresh" class="title-state">новый</x-ui.state>@endif</x-slot:badge>
        <x-slot:marks>
            @if ($offer->isUrgent())<x-offer.urgent label/>@endif
            @if ($offer->recommended)<x-offer.recommended label/>@endif
            <x-ui.vin-code :vin="$offer->vinFor(auth()->user())" :copy="$offer->vinOpenTo(auth()->user())" class="tag"/>
            <x-offer.tags :offer="$offer" :facts="false" :vat="false"/>
        </x-slot:marks>
        <x-slot:line>
            @if ($offer->settlement)<x-ui.place>{{ $offer->settlement->title() }}</x-ui.place>@endif
            @if ($left !== null && $left > 0)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}">приём <span data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at, 'ещё') }}</span></span>@elseif ($bids && !$gallery && !$offer->bidsOpen())<span class="text-ink-muted">приём закрыт</span>@endif
        </x-slot:line>
        <x-slot:tools>
            @if ($user?->canShare())@include('admin.offers.share-button', ['class' => 'bar-btn'])@endif
        </x-slot:tools>
        <x-slot:aside>
            @if ($price->shown())
                <span class="nums block whitespace-nowrap text-lg">@if ($price->withFrom())<span class="text-ink-muted">{{ $price::money($price->from) }}&nbsp;→</span> @endif<span class="font-bold">{{ $price::money($price->to) }}&nbsp;₽</span>@if ($price->vat) <span class="text-xs font-normal text-ink-muted">с НДС</span>@endif</span>
                @if ($price->declared)<span class="text-xs text-ink-dim nums">заявленная {{ $price::money($price->declared) }}</span>@endif
            @elseif ($gallery)
                <span class="text-sm text-accent-text">{{ $user?->isBuyer() ? 'Цена при выходе в продажу' : 'Скоро в продаже' }}</span>
                <x-offer.gallery-left :offer="$offer" class="block text-xs"/>
            @endif
        </x-slot:aside>
        <x-slot:actions>
            @if ($canBid)
                @php
                    // Админ подтверждает за менеджера — как в `x-offer.bid-form`.
                    $staff = $user->isAdmin() && ! $user->isManager();
                    $garage = $staff ? (bool) $offer->garage_allowed : $offer->garageAllowedFor($user);
                @endphp
                <form method="post" action="/offers/{{ $n }}/confirm" class="flex w-full flex-col gap-2" data-telegram-moment="bid" data-controller="bid" data-bid-asking-value="{{ $asking }}" data-bid-garage-value="{{ $garage ? 'true' : 'false' }}" data-bid-staff-value="{{ $staff ? 'true' : 'false' }}" data-action="submit->bid#guard change->bid#update">
                    @csrf
                    <div class="flex gap-2">
                        <input type="hidden" name="amount" data-bid-target="amount" value="{{ old('amount', $myBid?->amount) }}">
                        <input type="text" @unless ($garage) required @endunless inputmode="decimal" autocomplete="off" enterkeyhint="go" data-check-text="Укажите цену" class="field-input field-s nums min-w-0 flex-1" placeholder="Цена, ₽" aria-label="Цена, ₽" data-bid-target="display" data-action="input->bid#input" value="{{ old('amount', $myBid?->amount ? \App\Support\Money::nums($myBid->amount) : '') }}" data-detail-focus>
                        <button type="submit" class="btn btn-s btn-accent shrink-0" data-bid-target="submit">{{ $myBid ? 'Изменить' : 'Подтвердить' }}</button>
                    </div>
                    @error('amount')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                    @error('manager_id')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap items-center gap-1.5">
                        @if ($asking)
                            <button type="button" class="pill pill-plain nums" data-action="bid#set" data-bid-amount-param="{{ $asking }}">{{ \App\Support\Money::nums($asking) }}</button>
                            @foreach ($discounts as $percent => $price)<button type="button" class="pill pill-plain nums" data-action="bid#set" data-bid-amount-param="{{ $price }}">−{{ $percent }}%</button>@endforeach
                        @endif
                        <input name="comment" class="field-input field-s min-w-0 flex-1 sm:text-sm" placeholder="Комментарий" value="{{ old('comment', $myBid?->comment) }}">
                    </div>
                    @if ($garage || $staff)<x-offer.bid-choice :offer="$offer" :garage="$garage" :staff="$staff"/>@endif
                </form>
                @if ($myBid)
                    <span class="text-sm text-ink-muted">@if ($myBid->isGarage())<span class="font-semibold text-ink">В гараж</span>@else Ваша цена <span class="nums font-semibold text-ink">{{ \App\Support\Money::rub($myBid->amount) }}</span>@endif, {{ mb_strtolower($myBid->state->label()) }}</span>
                    <form method="post" action="/confirmations/{{ $myBid->id }}/withdraw" class="contents">@csrf<button type="submit" class="pill pill-plain">Отозвать</button></form>
                @endif
            @elseif ($myBid)
                <x-offer.my-bid :offer="$offer" :bid="$myBid" class="w-full"/>
            @elseif ($wantsInterest)
                @if ($myInterest)
                    <span class="flash flash-accent w-full text-sm">{{ $gallery ? ($user->isBuyer() ? 'Сообщим о выходе в продажу' : 'Интерес отправлен') : ($myInterest->state === InterestState::New ? ($manager ? $manager->shortName().' свяжется с вами' : 'Менеджер свяжется с Вами') : 'С Вами связались') }}</span>
                    <form method="post" action="/offers/{{ $n }}/interest" class="contents" data-turbo-confirm="Снять интерес?" data-turbo-confirm-label="Снять" @unless ($gallery && ! $manager) data-turbo-confirm-text="{{ $manager?->shortName() ?? 'Менеджер' }} увидит, что вы передумали" @endunless>
                        @csrf @method('delete')
                        <button type="submit" class="pill pill-plain">Передумал</button>
                    </form>
                @else
                    <form method="post" action="/offers/{{ $n }}/interest" class="flex w-full gap-2" @if (auth()->user()?->isBuyer()) data-telegram-moment="bid" @endif>
                        @csrf
                        <input name="comment" class="field-input field-s min-w-0 flex-1 sm:text-sm" placeholder="{{ $user->isBuyer() ? 'Пара слов менеджеру' : 'Что важно уточнить' }}" value="{{ old('comment') }}">
                        <button type="submit" class="btn btn-s btn-accent shrink-0">{{ $gallery && $user->isBuyer() ? 'Сообщить о продаже' : ($gallery || $user->isBuyer() ? 'Проявить интерес' : 'Узнать цену') }}</button>
                    </form>
                @endif
            @elseif ($bids && !$gallery && !$offer->bidsOpen() && $offer->state !== OfferState::Draft)
                <span class="text-sm text-ink-muted">Приём подтверждений закрыт</span>
            @endif
            @if (auth()->check() && ($offer->isGallery() || $offer->state !== \App\Offers\OfferState::Draft))<x-offer.favorite :offer="$offer" variant="pill"/>@endif
            @if ($canChat)<a href="/account/chats/offer/{{ $offer->number }}" class="pill pill-plain"><x-ui.icon name="chat" class="size-4"/> Чат@if ($chat?->unread_for_user) <span class="badge">{{ $chat->unread_for_user }}</span>@endif</a>@endif
            @if ($user?->isManager() && $offer->state === OfferState::Open && \App\Offers\Showing::pickable($user))@php $seen = \App\Offers\Showing::countsFor($user, [$offer->id])[$offer->id] ?? 0; @endphp<a href="{{ $href }}" data-show-offer="{{ $offer->id }}" class="pill pill-plain"><x-ui.icon name="users" class="size-4"/> @if ($seen)Открыто <span class="nums">{{ $seen }}</span>@else Открыть покупателям@endif</a>@endif
            {{-- Покупателю — его менеджер: кто и как позвонить, одной пилюлей. --}}
            @if ($manager?->phone)<a href="tel:+{{ $manager->phone }}" class="pill pill-plain"><x-ui.avatar :user="$manager" :size="20"/> {{ $manager->shortName() }} <span class="nums">{{ $manager->phoneFormatted() }}</span> <x-ui.icon name="phone" class="size-4"/></a>@endif
        </x-slot:actions>
        @if ($offer->publicDescription())<p class="mt-3 whitespace-pre-line text-sm text-ink-muted">{{ $offer->publicDescription() }}</p>@endif
        <x-slot:row><x-offer.site-table-row :offer="$offer" :context="$context"/></x-slot:row>
    </x-ui.row-card>
</x-ui.detail>
