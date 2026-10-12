{{-- Карточка строки таблицы предложений и галереи (фрейм detail) — почти весь редактор без ухода на страницу,
     одной лентой в порядке редактора (12.10.2026, владелец: «в окошке хаос»): шапка и дело сейчас (оценить, подтверждения,
     интерес строками), письма, «Цены», «Показ», «Транспортное средство», «Документы»; кадры (нажатие прячет или возвращает, порядок перетаскиванием, поворот, «+»; удаления нет),
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
    // Кнопки состояния — только уместные (OfferState::actions): в сделке — отмена сделки, «Снять с продажи» — у того, что в продаже.
    // Модератору — поля, кадры и документы: оценки, состояния, подтверждений, интереса и круга показа у него нет.
    $transitions = $admin ? collect($offer->stateActions())->mapWithKeys(fn ($label, $state) => [$state => [OfferState::from($state), $label]]) : collect();
    // «Опубликовать» у черновика и галереи — тремя пунктами: сейчас, в ближайший слот, в следующий (`x-offer.publish-items`).
    // Без цены продажи публиковать нечего — пунктов нет, пока её не поставили.
    $publishItems = $admin && in_array($offer->state, [OfferState::Draft, OfferState::Gallery], true) && ! $offer->isScheduled() && $offer->asking_price;
    // «Опубликовать» у черновика — пунктами слота; у сделки «вернуть в продажу» — обычным пунктом (отмена сделки).
    if ($offer->state !== OfferState::Sold) $transitions = $transitions->except(OfferState::Open->value);
    // Блок «Оценить» — у черновика без цены продажи; оценённому цену правят в «Ценах».
    $rate = $admin && $offer->state === OfferState::Draft && ! $offer->asking_price && ! $offer->isScheduled();
    // Оценённый черновик («Оцененные»), пока не в слоте — корректировки менеджеров и «+ Спросить менеджеров» (11.10.2026).
    $correct = $admin && $offer->state === OfferState::Draft && $offer->asking_price && ! $offer->isScheduled();
    // Закупочной нет — сначала она (вкладка «Без закупочной цены», 05.10.2026): у всех, и у модератора; у админа — на месте
    // «Оценить», цену продажи без закупочной не ставят. У Альфы первой — оценочная, закупочная от неё на лету.
    $floorFirst = $offer->state === OfferState::Draft && ! $offer->asking_price && ! $offer->floor_price && ! $offer->isScheduled();
    $byValue = $floorFirst && \App\Vendors\Vendor::ratesByValue($offer->vendor_id);
    // Рядом с «Оценить» — «В гараж»: менеджеру без цены продажи (04.10.2026), та же форма, что в «···» редактора.
    $garage = $rate && $offer->state->allows(OfferState::Garage);
    // И «Вывоз»: кто забирает ТС и куда (`AssignPickup`) — пока не забрали; цену после него всё равно ставить.
    $pickup = $rate && $offer->vendor?->workflow(\App\Workflow\Track::Service)?->is_active && ! $offer->pickedUp();
    $ways = $garage || $pickup;
    $way = $errors->hasAny(['mode', 'evacuator_id', 'evacuation_to', 'scope', 'from_region_id', 'to_region_id', 'executor', 'executor_id', 'carrier', 'cost']) ? 'pickup' : (old('stage') ? 'garage' : 'rate');
    $bids = $admin ? $offer->bids->sortBy([fn ($a, $b) => ($a->state === BidState::Active ? 0 : 1) <=> ($b->state === BidState::Active ? 0 : 1), ['amount', 'desc']]) : collect();
    $waiting = $bids->where('state', BidState::Active);
    $unread = $chats->sum('unread_for_staff');
    // Две колонки, которые не распирает содержимое (дата-время, VIN с кнопкой): иначе карточка листалось вбок.
    $grid = 'grid grid-cols-[repeat(2,minmax(0,1fr))] gap-x-2 gap-y-2.5';
@endphp
<x-ui.detail>
    <x-ui.row-card :href="'/offers/'.$n" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :facts="array_slice($offer->facts(), 1)">
        {{-- Состояние — словом у названия, а не среди кодов (владелец 10.10.2026: «статус тут не к месту»); слова «черновик»
             нет, как и в строке: вкладка и так говорит, на каком он шаге. --}}
        <x-slot:badge>@if ($offer->state !== OfferState::Draft)<x-ui.state :tone="$offer->state->tone()" class="title-state">{{ mb_strtolower($offer->state->labelFor(auth()->user())) }}</x-ui.state>@endif<x-ui.links :offer="$offer"/></x-slot:badge>
        <x-slot:media>@include('admin.offers.detail-photos')</x-slot:media>
        {{-- Под названием — что это за машина (состояние словом, коды чипами: №, убыток или ДЛ, VIN), ниже фразой — где она
             и сколько осталось (`line`): владелец 10.10.2026 «одно к машине относится, другое к фактам». --}}
        <x-slot:marks>
            {{-- Номер предложения и номер ДЛ или убытка копируются нажатием, как VIN; у убытка слова «Убыток» нет (05.10.2026). --}}
            @unless ($offer->state === OfferState::Draft)<span class="tag nums gap-1">№<x-ui.copy-code :value="(string) $n" done="Номер в буфере"/></span>@endunless
            @if ($offer->claim_ref)<span class="tag nums gap-1">@if ($offer->leaseRef())ДЛ @endif<x-ui.copy-code :value="$offer->claim_ref"/></span>@endif
            <x-ui.vin-code :vin="$offer->vin" class="tag"/>
        </x-slot:marks>
        <x-slot:line>
            @if ($offer->settlement)<x-ui.place>{{ $offer->settlement->title() }}</x-ui.place>@endif
            @if ($offer->car_place)<span>{{ mb_strtolower($offer->car_place->label()) }}</span>@endif
            @if ($left !== null && $left > 0)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}">приём <span data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at, 'ещё') }}</span></span>
            @elseif (! $gallery && $offer->closed() && auth()->user()->canManageCrm())<span class="text-ink-muted">приём закрыт</span>@endif
            {{-- Окно галереи (08.10.2026): висит — сколько осталось, кончилось — до какого числа висело. --}}
            @if ($gallery)<span class="nums {{ $offer->isGalleryEndingSoon() ? 'text-urgent' : 'text-accent-text' }}">в галерее <span data-controller="timer" data-timer-until-value="{{ $offer->gallery_until->toIso8601String() }}" data-timer-done-value="закрыта" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->gallery_until, 'ещё') }}</span></span>
            @elseif ($offer->gallery_from)<span class="nums text-ink-muted">в галерее до {{ $offer->gallery_until->translatedFormat('j M, H:i') }}</span>@endif
            @if ($offer->interests_count)<span class="nums text-accent-text">{{ $offer->interests_count }} {{ \App\Support\Plural::of($offer->interests_count, ['интерес', 'интереса', 'интересов']) }}</span>@endif
        </x-slot:line>
        {{-- Цен в шапке нет: закупочная, заявленная и продажи — поля «Деньги» ниже. --}}
        <x-slot:actions>
            {{-- Ряд кнопок над плашкой дела (12.10.2026, владелец выбрал вариант «В»): слот, сделка и чат слева, «Действия»
                 справа; ниже — плашка «Закупочная / Оценить / В гараж / Вывоз» или ответы менеджеров. --}}
            <div class="flex w-full flex-wrap items-center gap-2">
                @if ($admin && $offer->isScheduled())<x-offer.slot-menu :offer="$offer"/>@endif
                @if ($admin && $offer->deal)<x-ui.pill tone="open" href="/work/deals/{{ $offer->deal->id }}"><x-ui.icon name="deal" class="size-4"/> Сделка</x-ui.pill>@endif
                @if ($chats->isNotEmpty())<x-ui.pill :tone="$unread ? 'urgent' : 'plain'" href="/work/chats?preset=all&q={{ $n }}"><x-ui.icon name="chat" class="size-4"/> {{ $chats->count() === 1 ? 'Чат' : 'Чаты' }}@if ($chats->count() > 1 && ! $unread) <span class="nums text-ink-dim">{{ $chats->count() }}</span>@endif @if ($unread) <span class="badge">{{ $unread }}</span>@endif</x-ui.pill>@endif
                {{-- «Действия» (05.10.2026, начальник: одной кнопкой справа, как в приложениях) — меню группами, как в iOS: продлить
                     приём, опубликовать (сейчас или в слот), смена состояния; архив и удаление — красным последними. --}}
                @php
                    $extend = $admin && $offer->state === OfferState::Open && $offer->bids_close_at;
                    $purge = $admin && in_array($offer->state, [OfferState::Archived, OfferState::Cancelled], true);
                    // «Вернуть в продажу» из сделки и гаража — шторкой с ценами (`x-offer.resale-sheet`, стоит вне меню).
                    $resale = $admin && in_array($offer->state, [OfferState::Sold, OfferState::Garage], true);
                    // Отмена сделки — красным, с подтверждением: оба пункта у сделки (в продажу, в черновики) её гасят.
                    $calm = $offer->state === OfferState::Sold ? collect() : $transitions->reject(fn ($t) => $t[0] === OfferState::Archived || $t[0]->tone() === 'danger');
                    $danger = $transitions->diffKeys($calm);
                    // У сделки «в черновики» — внутри шторки «Отменить сделку» (12.10.2026), отдельным пунктом не нужен.
                    if ($resale && $offer->state === OfferState::Sold) $danger = $danger->except(OfferState::Draft->value);
                    // Черновик из таблицы Кати — «Убрать из таблицы Кати»: номер уходит из списка, в рабочих вкладках черновик и так стоит.
                    $listed = $admin && $offer->state === OfferState::Draft ? \App\Offers\LegacyClaim::listed($offer) : null;
                    $icon = fn (OfferState $s) => match ($s) { OfferState::Gallery => 'grid', OfferState::Draft => $offer->state === OfferState::Archived ? 'undo' : 'eye-off', OfferState::Archived => 'archive', default => 'flag' };
                @endphp
                @php $askMenu = ($correct || ($rate && ! $floorFirst)) && $offer->openEstimates->isEmpty() && $offer->declinedEstimates->isEmpty(); @endphp
                @if ($extend || $transitions->isNotEmpty() || $publishItems || $purge || $listed || $resale || $askMenu)
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
                                    <button type="button" class="menu-item" role="menuitem" data-controller="emit" data-action="emit#send menu#close" data-emit-event-param="urgent:open"><x-ui.icon name="fire" fill class="size-5 text-fire"/>Срочно…</button>
                                </div>
                            @endif
                            {{-- Менеджерам на оценку (без цены) или на корректировку (цена стоит, админ не уверен), 11.10.2026: пунктом, а не
                                 строкой у каждой карточки; отдали — ответы группой в карточке. --}}
                            @if ($askMenu)
                                <div class="menu-group">
                                    <button type="button" class="menu-item" role="menuitem" data-controller="emit" data-action="emit#send menu#close" data-emit-event-param="estimate-ask-{{ $n }}:open"><x-ui.icon name="users" class="size-5 text-ink-muted"/>{{ $correct ? 'Спросить менеджеров' : 'Отдать на оценку' }}</button>
                                </div>
                            @endif
                            @if ($listed)
                                <div class="menu-group">
                                    <form method="post" action="/offers/legacy/{{ $listed->id }}">
                                        @csrf @method('delete')
                                        <button class="menu-item" role="menuitem" data-action="menu#close"><x-ui.icon name="x" class="size-5 text-ink-muted"/>Убрать из таблицы Кати</button>
                                    </form>
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
                            @if ($danger->isNotEmpty() || $purge || $resale)
                                <div class="menu-group">
                                    @if ($resale)
                                        <button type="button" class="menu-item text-danger" role="menuitem" data-controller="emit" data-action="emit#send menu#close" data-emit-event-param="resale:open"><x-ui.icon name="undo" class="size-5"/>{{ $offer->state === OfferState::Sold && ! $offer->garageCar ? 'Отменить сделку' : 'Вернуть в продажу' }}</button>
                                    @endif
                                    @foreach ($danger as [$next, $label])
                                        <form method="post" action="/offers/{{ $n }}/state" @if ($offer->state === OfferState::Sold) data-turbo-confirm="Отменить сделку?" data-turbo-confirm-text="Подтверждение менеджера отклонится, неоплаченные счета сделки аннулируются" @else data-turbo-confirm="{{ $next->label() }}?" @endif>
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
            </div>
            @if ($rate || $floorFirst)
                {{-- «Оценить» (вкладка «Без цены»): только цена продажи — машина уходит в «Оцененные», карточка переходит к
                     следующей без цены; пустое поле — просто дальше. В продажу отправляют пачкой из «Оцененных». Под полем
                     ориентиры из закупки, если черновик сделан по контрпредложению: цены менеджеров, под ними админская. --}}
                {{-- Или «В гараж»: менеджеру сразу, без цены продажи; строка так же уходит, карточка — к следующей. После
                     ошибки (не выбран менеджер) открыт снова гараж. --}}
                <div class="flex w-full flex-col gap-3 rounded-(--radius-l) bg-surface-2 p-3" @if ($ways) data-controller="reveal" @endif>
                    @if ($ways)
                        <div class="segment">
                            <label><input type="radio" name="_way" value="rate" @checked($way === 'rate') data-action="reveal#pick"><span>{{ $floorFirst ? 'Закупочная' : 'Оценить' }}</span></label>
                            @if ($garage)<label><input type="radio" name="_way" value="garage" @checked($way === 'garage') data-action="reveal#pick"><span>В гараж</span></label>@endif
                            @if ($pickup)<label><input type="radio" name="_way" value="pickup" @checked($way === 'pickup') data-action="reveal#pick"><span>Вывоз</span></label>@endif
                        </div>
                    @endif
                    <div data-reveal-target="pane" data-reveal-key="rate">
                        @if ($floorFirst)
                        {{-- Закупочная (у Альфы — оценочная и посчитанная от неё закупочная): машина уходит в «Без продажной
                             цены», карточка — к следующей; пустое поле — просто дальше. Поля и кнопка — той же формы
                             карточки, что «Сохранить изменения» (08.10.2026, владелец: «должны одно и то же делать»):
                             своя форма гонялась с ней и оставляла карточку в странном состоянии. --}}
                        <div class="flex flex-col gap-2" data-controller="value-floor">
                            @if ($byValue)
                                <input type="text" name="value" form="detail-edit-{{ $n }}" data-save-own inputmode="decimal" autocomplete="off" enterkeyhint="next" class="field-input field-s nums !bg-surface" placeholder="Оценочная, ₽" aria-label="Оценочная, ₽"
                                    data-controller="digits" data-action="input->digits#format input->value-floor#sync" data-value-floor-target="value" value="{{ $offer->value ? \App\Support\Money::nums($offer->value) : '' }}" data-detail-focus>
                            @endif
                            <div class="flex gap-2">
                                <input type="text" name="floor_price" form="detail-edit-{{ $n }}" data-save-own inputmode="decimal" autocomplete="off" enterkeyhint="go" class="field-input field-s nums min-w-0 flex-1 !bg-surface" placeholder="Закупочная, ₽" aria-label="Закупочная, ₽"
                                    data-controller="digits" data-action="input->digits#format" data-value-floor-target="floor" value="" @unless ($byValue) data-detail-focus @endunless>
                                <button type="submit" form="detail-edit-{{ $n }}" name="then" value="advance" class="btn btn-s btn-accent shrink-0">Сохранить</button>
                            </div>
                        </div>
                        @else
                        {{-- Цена продажи — поле формы карточки, «Оценить» — её кнопка: одно сохранение с «Сохранить изменения». --}}
                        <div class="flex gap-2">
                            <input type="text" name="asking_price" form="detail-edit-{{ $n }}" data-save-own inputmode="decimal" autocomplete="off" enterkeyhint="go" class="field-input field-s nums min-w-0 flex-1 !bg-surface" placeholder="Цена продажи, ₽" aria-label="Цена продажи, ₽"
                                data-controller="digits" data-action="input->digits#format" value="" data-detail-focus>
                            <button type="submit" form="detail-edit-{{ $n }}" name="then" value="advance" class="btn btn-s btn-accent shrink-0">Оценить</button>
                        </div>
                        {{-- Оценка менеджером (11.10.2026): кто оценил — «Взять» / «Отклонить», кто думает — срок, «+ Отдать ещё»; группа —
                             только когда уже отдали, первый раз — пунктом «Действий» (владелец: «отдать на оценку в действия»). --}}
                        @if ($rate && ($offer->openEstimates->isNotEmpty() || $offer->declinedEstimates->isNotEmpty()))
                            @include('admin.offers.estimates')
                        @endif
                        @endif
                        @if ($offer->purchaseCar)
                            @include('admin.offers.purchase-prices', ['car' => $offer->purchaseCar])
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
            @elseif ($correct && ($offer->openEstimates->isNotEmpty() || $offer->declinedEstimates->isNotEmpty()))
                {{-- Цена поставлена, админ не уверен — ответы менеджеров на корректировку: «Согласен» или своя цена. --}}
                <div class="flex w-full flex-col rounded-(--radius-l) bg-surface-2 p-3">
                    @include('admin.offers.estimates', ['correction' => true])
                </div>
            @endif
            @if ($errors->has('state'))<x-ui.flash tone="danger" class="w-full">{{ $errors->first('state') }}</x-ui.flash>@endif
            @if ($resale)<x-offer.resale-sheet :offer="$offer"/>@endif
            @if ($publishItems)<x-offer.urgent-sheet :offer="$offer"/>@endif
            @if ($askMenu)<x-offer.estimate-ask :offer="$offer" :managers="$managers" :trigger="false"/>@endif
        </x-slot:actions>
        {{-- Письма — сразу под шапкой, над подтверждениями и полями (11.10.2026, владелец: «в окошке очень не хватает писем»;
             по ним заполняют поля и оценивают): все строками, как в «Почте», строка открывает окно поверх списка на письме. --}}
        @if ($letters && auth()->user()->canCrmMail())
            <section class="mt-4">
                <h2 class="detail-section">Письма <span class="nums font-normal text-ink-dim">{{ $letters }}</span></h2>
                <x-mail.letters :messages="$messages" :url="'/offers/'.$n.'/letters'" :asks="$asks" :stages="$stages"/>
            </section>
        @endif
        @if ($bids->isNotEmpty() || ($admin && $offer->state === OfferState::Open))
            <section class="mt-4">
                <h2 class="detail-section">Подтверждения @if ($waiting->isNotEmpty())<span class="nums font-normal text-ink-dim">{{ $waiting->count() }}</span>@endif</h2>
                @include('admin.offers.bids')
            </section>
        @endif
        {{-- Интерес — под подтверждениями строками `.list`, как все группы однотипного (12.10.2026: плашками с тегами — хаос). --}}
        @if ($admin && $offer->interests->isNotEmpty())
            <section class="mt-4">
                <h2 class="detail-section">Интерес <span class="nums font-normal text-ink-dim">{{ $offer->interests->count() }}</span></h2>
                <div class="list">
                    @foreach ($offer->interests as $interest)
                        <div class="row">
                            <x-ui.avatar :user="$interest->user" :size="36"/>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $interest->user->name }}</span>
                                <span class="row-sub">
                                    @if ($interest->user->manager)<span>покупатель {{ $interest->user->manager->shortName() }}</span>@endif
                                    @if ($interest->user->phone)<a href="tel:+{{ $interest->user->phone }}" class="nums whitespace-nowrap text-accent-text">{{ $interest->user->phoneFormatted() }}</a>@endif
                                    <span class="nums whitespace-nowrap">{{ $interest->created_at->translatedFormat('j M, H:i') }}</span>
                                </span>
                                @if ($interest->comment)<span class="mt-1 block text-sm">{{ $interest->comment }}</span>@endif
                            </span>
                            @if ($interest->state === InterestState::New)
                                <form method="post" action="/interests/{{ $interest->id }}" class="shrink-0">@csrf<input type="hidden" name="state" value="contacted"><button class="btn btn-s btn-quiet">Мы связались</button></form>
                            @elseif ($interest->state === InterestState::Contacted)
                                <form method="post" action="/interests/{{ $interest->id }}" class="shrink-0">@csrf<input type="hidden" name="state" value="closed"><button class="btn btn-s btn-quiet">Закрыть</button></form>
                            @else
                                <x-ui.state tone="closed" class="shrink-0">{{ mb_strtolower($interest->state->label()) }}</x-ui.state>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
        <form method="post" action="/offers/{{ $n }}" id="detail-edit-{{ $n }}" class="detail-edit mt-4 flex flex-col gap-5" data-controller="vin save-bar migtorg-diff" data-migtorg-diff-url-value="/offers/{{ $n }}" data-migtorg-diff-fields-value="{{ json_encode((object) \App\Offers\MigtorgDiff::of($offer)) }}" data-save-bar-partial-value="true" data-save-bar-dirty-value="{{ $errors->any() && old('_fields') !== null ? 'true' : 'false' }}" data-save-bar-sent-value="{{ json_encode(array_values((array) old('_fields', []))) }}" data-turbo-frame="detail">
            @csrf @method('put')
            {{-- Модератору в «Ценах» только закупочная — она уже сверху, пустой блок не рисуется. --}}
            @unless ($floorFirst && ! $admin)
            <section>
                <h2 class="detail-section">Цены</h2>
                {{-- Оценки из закупки — ориентир цены: у машины по контрпредложению всегда, а не только под «Оценить». --}}
                @if ($admin && $offer->purchaseCar && ! $rate && ! $floorFirst)
                    <div class="mb-3 rounded-(--radius-l) bg-surface-2 p-3">
                        <div class="text-sm font-medium">Закупка № {{ $offer->purchaseCar->purchase->number }}</div>
                        @include('admin.offers.purchase-prices', ['car' => $offer->purchaseCar])
                    </div>
                @endif
                @include('admin.offers.fields.money', ['askingElsewhere' => $rate && ! $floorFirst, 'floorElsewhere' => $floorFirst])
            </section>
            @endunless
            {{-- «Показ» — тот же модуль, что справа в редакторе: под «Ценами», режим по делу (у сделки и гаража — свой или нет). --}}
            <x-offer.showing :offer="$offer" :form="'detail-edit-'.$n" as="section"/>
            <section>
                <h2 class="detail-section">Транспортное средство</h2>
                @include('admin.offers.fields.car')
            </section>
        </form>
        <section class="mt-5">
            <h2 class="detail-section">Документы</h2>
            @include('admin.offers.papers-block')
        </section>
        {{-- Тронули поле — внизу листа выезжает «Сохранить изменения» (save_bar_controller, кнопка привязана к форме form=):
             подвалом карточки, вне прокрутки — всегда на экране, а не в конце формы. Уходят только тронутые. --}}
        <x-slot:foot><x-ui.save-bar :form="'detail-edit-'.$n"/></x-slot:foot>
        {{-- Поделиться — в полосу карточки справа, перед «Развернуть»; нечего отдавать (ни фото, ни цены) — кнопки нет. --}}
        <x-slot:tools>
            @include('admin.offers.detail-tools')
        </x-slot:tools>
        {{-- Свежая строка — с галочкой, если список с галочками («Оцененные», «Публикация»). --}}
        <x-slot:row>@if ($list)<x-offer.gallery-row :offer="$offer"/>@else<x-offer.table-row :offer="$offer" :checkable="! $list && \App\Http\Admin\OfferController::pickable(auth()->user(), request('preset'), filled(request('q')))" :group="request('preset') === 'slots' ? $offer->slot_at?->format('YmdHi') : null" :cols="$list ? null : \App\Http\Admin\OfferController::columns(request('preset'), auth()->user(), filled(request('q')))"/>@endif</x-slot:row>
    </x-ui.row-card>
</x-ui.detail>
