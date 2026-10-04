{{-- Карточка строки таблицы предложений и галереи (фрейм detail) — почти весь редактор без ухода на страницу,
     одной лентой: кадры (нажатие прячет или возвращает, порядок перетаскиванием, поворот, «+»; удаления нет),
     шапка с ценой, у черновика «Оценка» с «В продажу», у открытого — продлить приём и состояние; подтверждения
     и интерес; дальше поля редактора (деньги, ТС, состояние, менеджеры) — сохраняются сами при выходе из поля
     (autosave), без перерисовки карточки; в «Документах» — «Заполнить из документов» (окно «Из документов» поверх,
     после «Подставить» карточка перечитывается). Документы, маршрут, удаление кадров и история — в полном редакторе.
     Формы действий отвечают в карточку (DetailBack), строка — свежей из row (gallery — какой список её показывает). --}}
@php
    use App\Offers\{OfferState, BidState, InterestState};
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, auth()->user(), crm: true);
    // Срок приёма — админу: подтверждения принимает только он; модератору открытое — «В продаже».
    $left = $gallery || ! auth()->user()->canManageCrm() ? null : $offer->secondsLeft();
    // «В гараже» ставится на странице предложения: там выбирают менеджера и цену, одной кнопкой не обойтись.
    // Кнопки состояния — только уместные (OfferState::actions): в сделке меню нет, «Снять с продажи» — у того, что в продаже.
    // Модератору — поля, кадры и документы: оценки, состояния, подтверждений, интереса и круга показа у него нет.
    $transitions = $admin ? collect($offer->state->actions())->mapWithKeys(fn ($label, $state) => [$state => [OfferState::from($state), $label]]) : collect();
    // «Опубликовать» у черновика и галереи — тремя пунктами: сейчас, в ближайший слот, в следующий (`x-offer.publish-items`).
    $publishItems = $admin && in_array($offer->state, [OfferState::Draft, OfferState::Gallery], true) && ! $offer->isScheduled();
    $transitions = $transitions->except(OfferState::Open->value);
    $bids = $admin ? $offer->bids->sortBy([fn ($a, $b) => ($a->state === BidState::Active ? 0 : 1) <=> ($b->state === BidState::Active ? 0 : 1), ['amount', 'desc']]) : collect();
    $waiting = $bids->where('state', BidState::Active);
    $unread = $chats->sum('unread_for_staff');
    // Две колонки, которые не распирает содержимое (дата-время, VIN с кнопкой): иначе карточка листалось вбок.
    $grid = 'grid grid-cols-[repeat(2,minmax(0,1fr))] gap-x-2 gap-y-2.5';
@endphp
<x-ui.detail>
    <x-ui.row-card :href="'/offers/'.$n" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :facts="array_slice($offer->facts(), 1)">
        <x-slot:media>
            <div data-controller="photos" data-photos-url-value="/offers/{{ $n }}/media" data-photos-any-value="true" data-photos-mark-value="true">
                @include('admin.offers.photo-upload')
                @include('admin.offers.detail-photos')
                <x-ui.photos-all class="photos-all--under"/>
            </div>
        </x-slot:media>
        <x-slot:marks>
            {{-- Номер предложения и номер ДЛ или убытка копируются нажатием, как VIN. --}}
            @unless ($offer->state === OfferState::Draft)<span class="tag nums gap-1">№<x-ui.copy-code :value="(string) $n" done="Номер в буфере"/></span>@endunless
            @if ($offer->claim_ref)<span class="tag nums gap-1">{{ $offer->leaseRef() ? 'ДЛ' : 'Убыток' }}<x-ui.copy-code :value="$offer->claim_ref"/></span>@endif
            <span class="tag {{ match ($offer->state->tone()) { 'open' => 'tag-accent', 'urgent' => 'text-urgent', 'danger' => 'text-danger', default => '' } }} {{ $offer->state === OfferState::Draft ? 'text-ink-dim' : '' }}">{{ $offer->parkWord() ?? $offer->state->labelFor(auth()->user()) }}</span>
            @if ($left !== null && $left > 0)<span class="tag nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at) }}</span>
            @elseif (! $gallery && $offer->closed() && auth()->user()->canManageCrm())<span class="tag">приём закрыт</span>@endif
            @if ($offer->car_place)<x-ui.place class="tag">{{ $offer->car_place->label() }}</x-ui.place>@endif
            @if ($offer->settlement)<x-ui.place class="tag">{{ $offer->settlement->title() }}</x-ui.place>@endif
            <x-ui.vin-code :vin="$offer->vin" class="tag"/>
            @if ($gallery && $offer->interests_count)<span class="tag text-accent-text nums">{{ $offer->interests_count }} {{ \App\Support\Plural::of($offer->interests_count, ['интерес', 'интереса', 'интересов']) }}</span>@endif
        </x-slot:marks>
        {{-- Цен в шапке нет: закупочная, заявленная и продажи — поля «Деньги» ниже. --}}
        <x-slot:aside>
            @if ($gallery)<span class="text-sm text-accent-text">Скоро в продаже</span>@endif
        </x-slot:aside>
        <x-slot:actions>
            @if ($admin && $offer->isScheduled())
                <x-offer.slot-menu :offer="$offer"/>
            @elseif ($admin && $offer->state === OfferState::Draft)
                {{-- «Оценить» одним блоком: цена продажи и «В продажу» — сейчас или в слот (по умолчанию ближайший; карточка
                     переходит к следующему черновику; пустое поле — просто дальше), под ними ориентиры из закупки, если
                     черновик сделан по контрпредложению: цены менеджеров, под ними админская. --}}
                <div class="w-full rounded-(--radius-l) bg-surface-2 p-3">
                    <form method="post" action="/offers/{{ $n }}/publish" class="flex flex-col gap-2" data-controller="bid" data-bid-asking-value="0">
                        @csrf
                        <div class="flex gap-2">
                            <input type="hidden" name="asking_price" data-bid-target="amount" value="{{ $offer->asking_price }}">
                            <input type="text" inputmode="decimal" autocomplete="off" enterkeyhint="go" class="field-input field-s nums min-w-0 flex-1 !bg-surface" placeholder="Цена продажи, ₽" aria-label="Цена продажи, ₽"
                                data-bid-target="display" data-action="input->bid#input" value="{{ $offer->asking_price ? \App\Support\Money::nums($offer->asking_price) : '' }}" data-detail-focus>
                            <button type="submit" class="btn btn-s btn-accent shrink-0">В продажу</button>
                        </div>
                        <div class="segment">
                            @foreach (\App\Offers\Slots::choices() as $c)
                                <label class="!px-2 whitespace-nowrap"><input type="radio" name="when" value="{{ $c['when'] }}" @checked($c['when'] === \App\Offers\Slots::NEAREST)><span class="nums">{{ $c['at'] ? \App\Offers\Slots::short($c['at']) : 'Сейчас' }}</span></label>
                            @endforeach
                        </div>
                    </form>
                    @if ($car = $offer->purchaseCar)
                        @php $named = $car->activeOfferList()->sortByDesc('amount')->values(); @endphp
                        @if ($car->price_final || $named->isNotEmpty())
                            <dl class="mt-3 flex flex-col gap-2 text-sm">
                                @foreach ($named as $one)
                                    <div class="flex items-center justify-between gap-3">
                                        <dt class="flex min-w-0 items-center gap-2"><x-ui.avatar :user="$one->user" :size="20"/><span class="truncate">{{ $one->user->shortName() }}</span></dt>
                                        <dd class="nums whitespace-nowrap">{{ \App\Support\Money::rub($one->amount) }}</dd>
                                    </div>
                                @endforeach
                                @if ($car->price_final)
                                    <div class="flex items-center justify-between gap-3"><dt class="text-ink-dim">Админская цена</dt><dd class="nums font-medium">{{ \App\Support\Money::rub($car->price_final) }}</dd></div>
                                @endif
                            </dl>
                        @endif
                    @endif
                </div>
            @endif
            @if ($admin && $offer->state === OfferState::Open && $offer->bids_close_at)
                @foreach ([15 => '+15 мин', 60 => '+1 ч'] as $minutes => $label)
                    <form method="post" action="/offers/{{ $n }}/extend" class="contents">@csrf<input type="hidden" name="minutes" value="{{ $minutes }}"><button class="pill pill-plain nums">{{ $label }}</button></form>
                @endforeach
            @endif
            @if ($transitions->isNotEmpty() || $publishItems)
                <div class="contents" data-controller="menu">
                    <button type="button" class="pill pill-plain" data-action="menu#toggle" aria-haspopup="menu" aria-controls="detail-state-{{ $n }}">Состояние <x-ui.icon name="chevron-down" class="size-4"/></button>
                    <div id="detail-state-{{ $n }}" class="menu" popover data-menu-target="list" role="menu">
                        @if ($publishItems)<x-offer.publish-items :offer="$offer"/>@endif
                        @foreach ($transitions as [$next, $label])
                            <form method="post" action="/offers/{{ $n }}/state" @if (in_array($next, [OfferState::Archived, OfferState::Cancelled])) data-turbo-confirm="{{ $next->label() }}?" @endif>
                                @csrf<input type="hidden" name="state" value="{{ $next->value }}">
                                <button class="menu-item w-full {{ $next->tone() === 'danger' || $next === OfferState::Archived ? 'text-danger' : '' }}" role="menuitem" data-action="menu#close">{{ $label }}</button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @endif
            @if ($admin && $offer->deal)<x-ui.pill tone="open" href="/work/deals/{{ $offer->deal->id }}"><x-ui.icon name="deal" class="size-4"/> Сделка</x-ui.pill>@endif
            @if ($chats->isNotEmpty())<x-ui.pill :tone="$unread ? 'urgent' : 'plain'" href="/work/chats?preset=all&q={{ $n }}"><x-ui.icon name="chat" class="size-4"/> {{ $chats->count() === 1 ? 'Чат' : 'Чатов: '.$chats->count() }}@if ($unread) <span class="badge">{{ $unread }}</span>@endif</x-ui.pill>@endif
            <x-offer.migtorg :offer="$offer"/>
            @if ($errors->has('state'))<x-ui.flash tone="danger" class="w-full">{{ $errors->first('state') }}</x-ui.flash>@endif
        </x-slot:actions>
        {{-- Последнее письмо, как в редакторе и деле ТС; вся переписка — окном поверх списка (x-mail.window на странице). --}}
        @if ($bids->isNotEmpty())
            <div class="mt-4">@include('admin.offers.bids')</div>
        @endif
        @if ($admin && $offer->interests->isNotEmpty())
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
        <form method="post" action="/offers/{{ $n }}" class="detail-edit mt-4 flex flex-col gap-5" data-controller="vin autosave" data-turbo-frame="detail">
            @csrf @method('put')
            <section>
                <h2 class="detail-section">Цены</h2>
                @include('admin.offers.fields.money', ['askingElsewhere' => $offer->state === OfferState::Draft])
            </section>
            <section>
                <h2 class="detail-section">Транспортное средство</h2>
                @include('admin.offers.fields.car')
            </section>
            @include('admin.offers.fields.show')
        </form>
        <section class="mt-5">
            <h2 class="detail-section">Документы</h2>
            @include('admin.offers.papers-block')
        </section>
        {{-- Письма — под документами: последнее словами, вся переписка — окном поверх списка. --}}
        @if ($lastLetter && auth()->user()->canCrmMail())
            <section class="mt-5">
                <h2 class="detail-section">Письма <span class="nums font-normal text-ink-dim">{{ $letters }}</span></h2>
                <x-mail.last-letter :message="$lastLetter" :count="$letters" :url="'/offers/'.$n.'/letters'" :asks="$asks" compact/>
            </section>
        @endif
        {{-- Поделиться — в полосу карточки справа, перед «Развернуть»; нечего отдавать (ни фото, ни цены) — кнопки нет. --}}
        <x-slot:tools>
            @include('admin.offers.detail-tools')
        </x-slot:tools>
        <x-slot:row><x-offer.table-row :offer="$offer" :gallery="$list"/></x-slot:row>
    </x-ui.row-card>
</x-ui.detail>
