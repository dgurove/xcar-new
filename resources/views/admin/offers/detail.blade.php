{{-- Карточка строки таблицы предложений и галереи (фрейм detail) — почти весь редактор без ухода на страницу,
     одной лентой: кадры (нажатие прячет или возвращает, порядок перетаскиванием, поворот, «+»; удаления нет),
     шапка с ценой, у черновика без цены «Оценить», у открытого — продлить приём и состояние; подтверждения
     и интерес; дальше поля редактора (деньги, ТС, состояние, менеджеры) — сохраняются сами при выходе из поля
     (кнопка «Сохранить изменения» внизу, уходят только тронутые); в «Документах» — «Заполнить из документов» (окно «Из документов» поверх,
     после «Подставить» карточка перечитывается). Документы, маршрут, удаление кадров и история — в полном редакторе.
     Формы действий отвечают в карточку (DetailBack), строка — свежей из row (gallery — какой список её показывает). --}}
@php
    use App\Offers\{OfferState, BidState, InterestState};
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, auth()->user(), crm: true);
    // Срок приёма — админу: подтверждения принимает только он; модератору открытое — «В продаже».
    $left = $gallery || ! auth()->user()->canManageCrm() ? null : $offer->secondsLeft();
    // Кнопки состояния — только уместные (OfferState::actions): в сделке меню нет, «Снять с продажи» — у того, что в продаже.
    // Модератору — поля, кадры и документы: оценки, состояния, подтверждений, интереса и круга показа у него нет.
    $transitions = $admin ? collect($offer->state->actions())->mapWithKeys(fn ($label, $state) => [$state => [OfferState::from($state), $label]]) : collect();
    // «Опубликовать» у черновика и галереи — тремя пунктами: сейчас, в ближайший слот, в следующий (`x-offer.publish-items`).
    // Без цены продажи публиковать нечего — пунктов нет, пока её не поставили.
    $publishItems = $admin && in_array($offer->state, [OfferState::Draft, OfferState::Gallery], true) && ! $offer->isScheduled() && $offer->asking_price;
    $transitions = $transitions->except(OfferState::Open->value);
    // Блок «Оценить» — у черновика без цены продажи; оценённому цену правят в «Ценах».
    $rate = $admin && $offer->state === OfferState::Draft && ! $offer->asking_price && ! $offer->isScheduled();
    // Рядом с «Оценить» — «В гараж»: менеджеру без цены продажи (04.10.2026), та же форма, что в «···» редактора.
    $garage = $rate && $offer->state->allows(OfferState::Garage);
    // И «Вывоз»: кто забирает ТС и куда (`AssignPickup`) — пока не забрали; цену после него всё равно ставить.
    $pickup = $rate && $offer->vendor?->workflow(\App\Workflow\Track::Service)?->is_active && ! $offer->pickedUp();
    $ways = $garage || $pickup;
    $way = $errors->hasAny(['evacuator_id', 'evacuation_to']) ? 'pickup' : (old('stage') ? 'garage' : 'rate');
    $bids = $admin ? $offer->bids->sortBy([fn ($a, $b) => ($a->state === BidState::Active ? 0 : 1) <=> ($b->state === BidState::Active ? 0 : 1), ['amount', 'desc']]) : collect();
    $waiting = $bids->where('state', BidState::Active);
    $unread = $chats->sum('unread_for_staff');
    // Две колонки, которые не распирает содержимое (дата-время, VIN с кнопкой): иначе карточка листалось вбок.
    $grid = 'grid grid-cols-[repeat(2,minmax(0,1fr))] gap-x-2 gap-y-2.5';
@endphp
<x-ui.detail>
    <x-ui.row-card :href="'/offers/'.$n" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :facts="array_slice($offer->facts(), 1)">
        <x-slot:badge><x-ui.links :offer="$offer"/></x-slot:badge>
        <x-slot:media>@include('admin.offers.detail-photos')</x-slot:media>
        <x-slot:marks>
            {{-- Номер предложения и номер ДЛ или убытка копируются нажатием, как VIN. --}}
            @unless ($offer->state === OfferState::Draft)<span class="tag nums gap-1">№<x-ui.copy-code :value="(string) $n" done="Номер в буфере"/></span>@endunless
            @if ($offer->claim_ref)<span class="tag nums gap-1">{{ $offer->leaseRef() ? 'ДЛ' : 'Убыток' }}<x-ui.copy-code :value="$offer->claim_ref"/></span>@endif
            {{-- Слова «черновик» нет, как и в строке: вкладка и так говорит, на каком он шаге; у черновика с парковки — «парковка с …». --}}
            @if ($offer->state !== OfferState::Draft)<span class="tag {{ match ($offer->state->tone()) { 'open' => 'tag-accent', 'urgent' => 'text-urgent', 'danger' => 'text-danger', default => '' } }} {{ $offer->state === OfferState::Draft ? 'text-ink-dim' : '' }}">{{ $offer->state->labelFor(auth()->user()) }}</span>@endif
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
            @elseif ($rate)
                {{-- «Оценить» (вкладка «Без цены»): только цена продажи — машина уходит в «Оцененные», карточка переходит к
                     следующей без цены; пустое поле — просто дальше. В продажу отправляют пачкой из «Оцененных». Под полем
                     ориентиры из закупки, если черновик сделан по контрпредложению: цены менеджеров, под ними админская. --}}
                {{-- Или «В гараж»: менеджеру сразу, без цены продажи; строка так же уходит, карточка — к следующей. После
                     ошибки (не выбран менеджер) открыт снова гараж. --}}
                <div class="flex w-full flex-col gap-3 rounded-(--radius-l) bg-surface-2 p-3" @if ($ways) data-controller="reveal" @endif>
                    @if ($ways)
                        <div class="segment">
                            <label><input type="radio" name="_way" value="rate" @checked($way === 'rate') data-action="reveal#pick"><span>Оценить</span></label>
                            @if ($garage)<label><input type="radio" name="_way" value="garage" @checked($way === 'garage') data-action="reveal#pick"><span>В гараж</span></label>@endif
                            @if ($pickup)<label><input type="radio" name="_way" value="pickup" @checked($way === 'pickup') data-action="reveal#pick"><span>Вывоз</span></label>@endif
                        </div>
                    @endif
                    <div data-reveal-target="pane" data-reveal-key="rate">
                        <form method="post" action="/offers/{{ $n }}/rate" class="flex gap-2" data-controller="bid" data-bid-asking-value="0">
                            @csrf
                            <input type="hidden" name="asking_price" data-bid-target="amount" value="">
                            <input type="text" inputmode="decimal" autocomplete="off" enterkeyhint="go" class="field-input field-s nums min-w-0 flex-1 !bg-surface" placeholder="Цена продажи, ₽" aria-label="Цена продажи, ₽"
                                data-bid-target="display" data-action="input->bid#input" value="" data-detail-focus>
                            <button type="submit" class="btn btn-s btn-accent shrink-0">Оценить</button>
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
                    @if ($garage)
                        <div data-reveal-target="pane" data-reveal-key="garage" hidden>
                            <x-offer.garage-form :offer="$offer" :managers="$managers" prefix="detail-garage-{{ $n }}"/>
                        </div>
                    @endif
                    @if ($pickup)
                        <div data-reveal-target="pane" data-reveal-key="pickup" hidden>
                            <x-offer.pickup-form :offer="$offer" :managers="$managers" prefix="detail-pickup-{{ $n }}"/>
                        </div>
                    @endif
                </div>
            @endif
            @if ($admin && $offer->deal)<x-ui.pill tone="open" href="/work/deals/{{ $offer->deal->id }}"><x-ui.icon name="deal" class="size-4"/> Сделка</x-ui.pill>@endif
            @if ($chats->isNotEmpty())<x-ui.pill :tone="$unread ? 'urgent' : 'plain'" href="/work/chats?preset=all&q={{ $n }}"><x-ui.icon name="chat" class="size-4"/> {{ $chats->count() === 1 ? 'Чат' : 'Чатов: '.$chats->count() }}@if ($unread) <span class="badge">{{ $unread }}</span>@endif</x-ui.pill>@endif
            {{-- «Действия» (05.10.2026, начальник: одной кнопкой справа, как в приложениях) — меню группами, как в iOS: продлить
                 приём, опубликовать (сейчас или в слот), смена состояния; архив и удаление — красным последними. --}}
            @php
                $extend = $admin && $offer->state === OfferState::Open && $offer->bids_close_at;
                $purge = $admin && in_array($offer->state, [OfferState::Archived, OfferState::Cancelled], true);
                $calm = $transitions->reject(fn ($t) => $t[0] === OfferState::Archived || $t[0]->tone() === 'danger');
                $danger = $transitions->diffKeys($calm);
                $icon = fn (OfferState $s) => match ($s) { OfferState::Gallery => 'grid', OfferState::Draft => $offer->state === OfferState::Archived ? 'undo' : 'eye-off', OfferState::Archived => 'archive', default => 'flag' };
            @endphp
            @if ($extend || $transitions->isNotEmpty() || $publishItems || $purge)
                <div class="contents" data-controller="menu">
                    <button type="button" class="pill pill-plain ml-auto" data-action="menu#toggle" aria-haspopup="menu" aria-controls="detail-actions-{{ $n }}">Действия <x-ui.icon name="chevron-down" class="size-4"/></button>
                    <div id="detail-actions-{{ $n }}" class="menu" popover data-menu-target="list" data-align="end" role="menu">
                        @if ($extend)
                            <div class="menu-group">
                                <div class="menu-head">Продлить приём</div>
                                @foreach ([15 => '15 минут', 60 => '1 час'] as $minutes => $label)
                                    <form method="post" action="/offers/{{ $n }}/extend">@csrf<input type="hidden" name="minutes" value="{{ $minutes }}"><button class="menu-item" role="menuitem" data-action="menu#close"><x-ui.icon name="clock" class="size-5 text-ink-muted"/><span class="nums">+{{ $label }}</span></button></form>
                                @endforeach
                            </div>
                        @endif
                        @if ($publishItems)
                            <div class="menu-group">
                                <div class="menu-head">Опубликовать</div>
                                @foreach (\App\Offers\Slots::choices() as $c)
                                    <form method="post" action="/offers/{{ $n }}/state">
                                        @csrf<input type="hidden" name="state" value="open"><input type="hidden" name="when" value="{{ $c['when'] }}">
                                        <button class="menu-item" role="menuitem" data-action="menu#close"><x-ui.icon :name="$c['at'] ? 'clock' : 'send'" class="size-5 text-ink-muted"/>{{ $c['at'] ? str_replace(', ', ' в ', \App\Offers\Slots::label($c['at'])) : 'Сейчас' }}</button>
                                    </form>
                                @endforeach
                            </div>
                        @endif
                        @if ($calm->isNotEmpty())
                            <div class="menu-group">
                                @foreach ($calm as [$next, $label])
                                    <form method="post" action="/offers/{{ $n }}/state">
                                        @csrf<input type="hidden" name="state" value="{{ $next->value }}">
                                        <button class="menu-item" role="menuitem" data-action="menu#close"><x-ui.icon :name="$icon($next)" class="size-5 text-ink-muted"/>{{ $label }}</button>
                                    </form>
                                @endforeach
                            </div>
                        @endif
                        @if ($danger->isNotEmpty() || $purge)
                            <div class="menu-group">
                                @foreach ($danger as [$next, $label])
                                    <form method="post" action="/offers/{{ $n }}/state" data-turbo-confirm="{{ $next->label() }}?">
                                        @csrf<input type="hidden" name="state" value="{{ $next->value }}">
                                        <button class="menu-item text-danger" role="menuitem" data-action="menu#close"><x-ui.icon :name="$icon($next)" class="size-5"/>{{ $label }}</button>
                                    </form>
                                @endforeach
                                {{-- Из архива — навсегда, со всем связанным (PurgeOffer): кадры, документы, сделки, чаты, счета. --}}
                                @if ($purge)
                                    <form method="post" action="/offers/{{ $n }}/purge" data-turbo-frame="_top" data-turbo-confirm="Удалить навсегда? Фото, документы, сделки, чаты и счета по нему удалятся без возврата" data-turbo-confirm-label="Удалить">
                                        @csrf<button class="menu-item text-danger" role="menuitem" data-action="menu#close"><x-ui.icon name="trash" class="size-5"/>Удалить навсегда</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endif
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
        <form method="post" action="/offers/{{ $n }}" class="detail-edit mt-4 flex flex-col gap-5" data-controller="vin save-bar migtorg-diff" data-migtorg-diff-fields-value="{{ json_encode((object) \App\Offers\MigtorgDiff::of($offer)) }}" data-save-bar-partial-value="true" data-save-bar-dirty-value="{{ $errors->any() && old('_fields') !== null ? 'true' : 'false' }}" data-save-bar-sent-value="{{ json_encode(array_values((array) old('_fields', []))) }}" data-turbo-frame="detail">
            @csrf @method('put')
            <section>
                <h2 class="detail-section">Цены</h2>
                @include('admin.offers.fields.money', ['askingElsewhere' => $rate])
            </section>
            <section>
                <h2 class="detail-section">Транспортное средство</h2>
                @include('admin.offers.fields.car')
            </section>
            @include('admin.offers.fields.show')
            {{-- Тронули поле — снизу выезжает «Сохранить изменения» (save_bar_controller); уходят только тронутые. --}}
            <x-ui.save-bar/>
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
        {{-- Свежая строка — с галочкой, если список с галочками («Оцененные», «Публикация»). --}}
        <x-slot:row><x-offer.table-row :offer="$offer" :gallery="$list" :checkable="$admin && ! $list && in_array(request('preset'), ['priced', 'slots'], true) && blank(request('q'))" :group="$offer->slot_at?->format('YmdHi')" :cols="$list ? null : \App\Http\Admin\OfferController::columns(request('preset'), auth()->user(), filled(request('q')))"/></x-slot:row>
    </x-ui.row-card>
</x-ui.detail>
