@php
    use App\Cars\{Body, Transmission, Drive, Fuel, DamageCause, DamageZone, Papers};
    use App\Offers\{OfferState, BidState, InterestState};
    $n = $offer->number;
    // «В гараже» ставит не кнопка состояния, а «Отдать в гараж»: там выбирают менеджера и цену.
    // Кнопки состояния — только уместные (OfferState::actions): в сделке меню нет, «Снять с продажи» — у того, что в продаже.
    // Модератор правит только поля черновика: состояния, гаража, подтверждений и круга показа у него нет.
    $transitions = $admin ? collect($offer->state->actions())->mapWithKeys(fn ($label, $state) => [$state => [OfferState::from($state), $label]]) : collect();
    $garage = $offer->state === OfferState::Garage ? \App\Garage\Car::with('manager')->where('offer_id', $offer->id)->first() : null;
    // Машину из гаража уводит только «Отдали по ошибке» там же: кнопки состояния тут отбились бы ошибкой.
    if ($garage) $transitions = collect();
    // Руками в гараж — не из сделки: идущую сначала отменяют, гаражная сделка сама доводит машину туда маршрутом.
    $canGarage = $admin && ! $garage && $offer->state !== OfferState::Sold && $offer->state->allows(OfferState::Garage);
    // Подтверждения: ждущие по сумме вниз, потом решённые.
    $bids = $admin ? $offer->bids->sortBy([fn ($a, $b) => ($a->state === BidState::Active ? 0 : 1) <=> ($b->state === BidState::Active ? 0 : 1), ['amount', 'desc']]) : collect();
    $waiting = $bids->where('state', BidState::Active);
    $grid = 'grid grid-cols-2 gap-3 @4xl:grid-cols-3';
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Предложения', '/']" cache="no-cache">
    <x-slot:badge><x-ui.links :offer="$offer"/></x-slot:badge>
    {{-- Под заголовком — только состояние и метки, пилюлями одного роста (номер, убыток и документы — в полях и блоках
         ниже). Метки: выбранные пилюлями, «+» и нажатие на метку — шторка со списком и своей меткой; галки ходят в форму
         оффера через form=, пилюли перерисовывает tag-chips сразу. Срок приёма, чаты и импорт — только когда они есть. --}}
    @php
        $own = $offer->tag_colors ?? [];
        $tagStyle = fn (string $name) => \App\Offers\Tag::style(\App\Offers\Tag::colorOf($name, $own));
    @endphp
    <div class="offer-head -mt-3 mb-4 flex flex-wrap items-center gap-1.5">
        @if ($garage)
            <x-ui.pill tone="plain" :href="$garage->url()" data-turbo="false">В гараже, {{ $garage->manager?->shortName() ?? 'взяли под себя' }}</x-ui.pill>
        @elseif ($admin && $offer->isScheduled())
            <x-offer.slot-menu :offer="$offer"/>
        @elseif ($admin && $offer->deal)
            {{-- В сделке пилюля состояния и есть вход в сделку: «Идёт сделка ›». --}}
            <x-ui.pill :tone="$offer->state->tone()" href="/work/deals/{{ $offer->deal->id }}">{{ $offer->state->label() }} ›</x-ui.pill>
        @else
            <x-ui.pill :tone="$offer->state->tone()">{{ $offer->parkWord() ?? $offer->state->labelFor(auth()->user()) }}</x-ui.pill>
        @endif
        @if ($offer->parkWord())
            <form method="post" action="/offers/{{ $n }}/unlist" class="contents" data-turbo-confirm="Снять с продажи? Черновик удалится, ТС останется на парковке">@csrf<button type="submit" class="pill pill-plain">Снять с продажи</button></form>
        @endif
        {{-- Срок приёма и продление — админу: подтверждения принимает только он. --}}
        @if (! $admin)
        @elseif ($offer->closed())
            <x-ui.pill tone="closed">Приём закрыт с {{ $offer->bids_close_at->translatedFormat('j M, H:i') }}</x-ui.pill>
        @elseif ($offer->bids_close_at && $offer->state === OfferState::Open)
            <x-ui.pill tone="plain"><span class="nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at) }}</span></x-ui.pill>
        @endif
        @if ($admin && $offer->state === OfferState::Open && $offer->bids_close_at)
            {{-- Продлить приём на ходу, как в закупке: от текущего срока, если он не прошёл, иначе от сейчас. --}}
            @foreach ([15 => '+15 мин', 60 => '+1 ч'] as $minutes => $label)
                <form method="post" action="/offers/{{ $n }}/extend" class="contents">@csrf<input type="hidden" name="minutes" value="{{ $minutes }}"><button class="pill pill-plain nums">{{ $label }}</button></form>
            @endforeach
        @endif
        @if ($chats->isNotEmpty())<x-ui.pill :tone="$chats->sum('unread_for_staff') ? 'urgent' : 'plain'" href="/work/chats?preset=all&q={{ $offer->number }}"><x-ui.icon name="chat" class="size-4"/> {{ $chats->count() === 1 ? 'Чат' : 'Чатов: '.$chats->count() }}@if ($chats->sum('unread_for_staff')) <span class="badge">{{ $chats->sum('unread_for_staff') }}</span>@endif</x-ui.pill>@endif
        @if ($admin)
            <div class="contents" data-controller="sheet tag-chips" data-action="change->tag-chips#render">
                <span class="contents" data-tag-chips-target="chips">
                    @foreach ($offer->tags ?? [] as $name)<button type="button" class="pill pill-tag" style="{{ $tagStyle($name) }}" data-action="sheet#open">{{ $name }}</button>@endforeach
                </span>
                <button type="button" class="pill pill-plain" data-action="sheet#open" aria-label="Метки"><x-ui.icon name="plus" class="size-4"/><span data-tag-chips-target="word" @if ($offer->tags) hidden @endif>Метка</span></button>
                <x-ui.sheet id="offer-tags" title="Метки">
                    <div class="flex flex-col gap-4">
                        @include('admin.offers.fields.tags', ['form' => 'offer-form', 'label' => false])
                        <x-ui.button type="button" block data-action="sheet#close">Готово</x-ui.button>
                    </div>
                </x-ui.sheet>
            </div>
            <span class="ml-auto flex items-center gap-1">
                @include('admin.offers.share-button')
            </span>
        @endif
        @if ($errors->has('state'))<x-ui.flash tone="danger" class="w-full">{{ $errors->first('state') }}</x-ui.flash>@endif
    </div>

    {{-- На телефоне блоки идут в одну колонку по order-*, на десктопе обёртки становятся колонками. --}}
    {{-- Колонки — по ширине содержимого (@container): с открытой справа шторкой документов редактор в одну колонку. --}}
    <div class="@container">
    <div class="grid grid-cols-1 items-start gap-4 @4xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="contents @4xl:col-start-2 @4xl:row-start-1 @4xl:flex @4xl:flex-col @4xl:gap-4">
            @if ($bids->isNotEmpty())
            {{-- Подтверждения: без сделки — выбрать победителя (оранжевым, пока ждут). Со сделкой это и есть карточка «Сделка»:
                 принятое, «Открыть сделку» и резерв; деньги и заметка — на странице сделки. --}}
            <x-ui.card :title="$offer->deal ? 'Сделка' : 'Подтверждения'.($waiting->isNotEmpty() ? ' '.$waiting->count() : '')" class="order-1 {{ ! $offer->deal && $waiting->isNotEmpty() ? 'box-urgent' : '' }}">
                @include('admin.offers.bids')
            </x-ui.card>
            @endif

            @if ($admin && $offer->positions->isNotEmpty())
                @include('admin.offers.route')
            @endif

            @if ($chats->isNotEmpty())
            <x-ui.card title="Чаты" class="order-5">
                <div class="flex flex-col divide-y divide-line/40">
                    @foreach ($chats as $chat)
                        <a href="/work/chats/{{ $chat->id }}" class="flex items-center gap-3 py-2">
                            <x-ui.avatar :user="$chat->user" :size="36"/>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-2"><span class="truncate {{ $chat->unread_for_staff ? 'font-medium' : '' }}">{{ $chat->displayName() }}</span><span class="ml-auto shrink-0 text-sm text-ink-dim">{{ $chat->last_message_at?->translatedFormat($chat->last_message_at->isToday() ? 'H:i' : 'j M') }}</span></div>
                                <div class="truncate text-sm text-ink-muted">{{ $chat->last_text ? \Illuminate\Support\Str::limit($chat->last_text, 80) : 'Файл' }}</div>
                            </div>
                            @if ($chat->unread_for_staff)<span class="badge">{{ $chat->unread_for_staff }}</span>@endif
                        </a>
                    @endforeach
                </div>
            </x-ui.card>
            @endif

            @if ($admin && $offer->interests->isNotEmpty())
            <x-ui.card title="Интерес" class="order-5">
                <div class="flex flex-col gap-2">
                    @foreach ($offer->interests as $interest)
                        <div class="box-nested">
                            <div class="flex flex-wrap items-center gap-1.5"><x-ui.person :user="$interest->user" full/>@if ($interest->user->manager)<span class="text-sm text-ink-muted">покупатель</span><x-ui.person :user="$interest->user->manager"/>@endif @if ($interest->user->phone)<a href="tel:+{{ $interest->user->phone }}" class="tag nums">{{ $interest->user->phoneFormatted() }}</a>@endif<span class="tag">{{ $interest->state->label() }}</span><span class="tag nums">{{ $interest->created_at->translatedFormat('j M, H:i') }}</span></div>
                            @if ($interest->comment)<div class="mt-1 text-sm">{{ $interest->comment }}</div>@endif
                            @if ($interest->state === InterestState::New)
                                <form method="post" action="/interests/{{ $interest->id }}" class="mt-2">@csrf<input type="hidden" name="state" value="contacted"><x-ui.button size="sm" variant="secondary">Связались</x-ui.button></form>
                            @elseif ($interest->state === InterestState::Contacted)
                                <form method="post" action="/interests/{{ $interest->id }}" class="mt-2">@csrf<input type="hidden" name="state" value="closed"><x-ui.button size="sm" variant="ghost">Закрыть</x-ui.button></form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
            @endif

            {{-- Деньги — справа над «Историей», у всех; поля ходят в форму оффера через form=. На телефоне — сразу под ТС.
                 Срок приёма, кому показывать и галки показа — «Показ» под описанием ТС. --}}
            <x-ui.card title="Цены" class="order-3">
                @include('admin.offers.fields.money', ['form' => 'offer-form', 'withTags' => false])
            </x-ui.card>

            {{-- История — последние четыре записи, остальное по «Ещё N»: лента в тридцать строк занимала экран. --}}
            @php $events = $offer->events->take(30); @endphp
            <x-ui.card title="История" class="order-6">
                <div class="flex flex-col gap-3 text-sm">
                    @foreach ($events->take(4) as $e)@include('admin.offers.event', ['event' => $e])@endforeach
                    @if ($events->count() > 4)
                        <details class="group flex flex-col gap-3">
                            <summary class="cursor-pointer list-none text-ink-muted group-open:hidden">Ещё {{ $events->count() - 4 }}</summary>
                            <div class="flex flex-col gap-3">
                                @foreach ($events->slice(4) as $e)@include('admin.offers.event', ['event' => $e])@endforeach
                            </div>
                        </details>
                    @endif
                </div>
            </x-ui.card>
        </div>

        <form method="post" action="/offers/{{ $n }}" id="offer-form" data-controller="vin draft save-bar next{{ $empty ? ' drop-empty' : '' }}" data-save-bar-dirty-value="{{ $errors->any() ? 'true' : 'false' }}" @if ($empty) data-drop-empty-url-value="/offers/{{ $n }}/drop-empty" @endif class="contents @4xl:col-start-1 @4xl:row-start-1 @4xl:flex @4xl:flex-col @4xl:gap-4">
            @csrf @method('put')

            <x-ui.card title="Транспортное средство" class="order-2">
                <x-mail.reader-diffs form="offer-form" class="mb-3"/>
                @if ($empty)<div class="mb-3"><x-ui.paste/></div>@endif
                @include('admin.offers.fields.car')
                @include('admin.offers.fields.show', ['summary' => $showingSummary])
            </x-ui.card>

        </form>

        {{-- Кадры письма прикрепляются после «Завести» (ImportThreadFiles): строка хода и заглушки в ряду, attach_controller
             переспрашивает их, пока строка есть. --}}
        <x-ui.card title="Фотографии" class="order-3 @4xl:col-span-2" data-controller="photos attach" data-attach-url-value="/offers/{{ $n }}/attach" data-photos-url-value="/offers/{{ $n }}/media" data-photos-any-value="true" data-photos-mark-value="true">
            <x-slot:actions><div class="flex items-center gap-4"><x-ui.photos-expand/><x-ui.photos-all/></div></x-slot:actions>
            <x-mail.attach-line :model="$offer"/>
            @include('admin.offers.photo-upload')
            @include('admin.offers.gallery')
        </x-ui.card>

        <x-ui.card title="Документы" class="order-4 @4xl:col-span-2">
            @include('admin.offers.papers-block', ['reader' => true, 'auto' => $fromMail])
        </x-ui.card>

        {{-- Письма — под документами, как в деле ТС: последнее словами, вся переписка и ответ — окном поверх редактора. --}}
        @if ($lastLetter && auth()->user()->canCrmMail())
            <x-ui.card title="Письма" :count="$letters" class="order-4 @4xl:col-span-2">
                <x-mail.last-letter :message="$lastLetter" :count="$letters" :url="'/offers/'.$n.'/letters'" :asks="$asks"/>
            </x-ui.card>
        @endif
    </div>
    </div>

    {{-- Гараж минуя подтверждение (x-offer.garage-form): этап, кому, кто платит поставщику. --}}
    @if ($canGarage)
        <div data-controller="sheet" data-action="garage:open@window->sheet#open" class="contents">
            <x-ui.sheet id="offer-garage" title="Отдать в гараж">
                <x-offer.garage-form :offer="$offer" :managers="$managers"/>
            </x-ui.sheet>
        </div>
    @endif

    {{-- Черновик из писем до первого сохранения: «Отменить» (черновика не было, цепочка снова в «Из писем»), «Не заявка»
         (и цепочку в архив), «Сохранить». После сохранения у черновика — «Опубликовать» той же формой (`then=open`):
         поля сохраняются, потом публикация; из «···» она уходит, чтобы не стоять дважды. --}}
    @php
        $draft = $offer->state === OfferState::Draft;
        // «Опубликовать» — с выбором слота: у черновика шторкой из плашки, у галереи тремя пунктами в «···».
        $publishItems = $admin && $offer->state === OfferState::Gallery && ! $offer->isScheduled();
        if ($draft || $offer->state === OfferState::Gallery) $transitions = $transitions->except(OfferState::Open->value);
    @endphp
    <x-mail.window :url="$window" :title="$offer->titleWithYear()"/>
    @php $deletable = ! $fromMail && $offer->isDeletableBy(auth()->user()); @endphp
    <x-ui.action-bar data-controller="sheet">
        @if ($fromMail)
            <form method="post" action="/offers/{{ $n }}/drop" class="contents">@csrf<x-ui.button variant="ghost" class="shrink-0 px-3 sm:px-7">Отменить</x-ui.button></form>
            <form method="post" action="/offers/{{ $n }}/drop" class="contents" data-turbo-confirm="Не заявка? Цепочка уйдёт в архив">@csrf<input type="hidden" name="decline" value="1"><x-ui.button variant="ghost" class="shrink-0 px-3 sm:px-7">Не заявка</x-ui.button></form>
            <x-ui.button form="offer-form" class="min-w-0 flex-1">Сохранить</x-ui.button>
        @elseif ($draft && ! $admin)
            {{-- Модератор заводит пачку подряд: «+ Новый» сохраняет и открывает следующий черновик с тем же вендором; публикует админ.
                 Свой черновик удаляет сам — корзиной с подтверждением; чужой — нет. --}}
            @if ($deletable)
                <form method="post" action="/offers/{{ $n }}" class="draft-delete contents" data-turbo-confirm="Удалить черновик? Фото и документы удалятся вместе с ним">@csrf @method('delete')<x-ui.button variant="secondary" round class="btn-lg shrink-0" aria-label="Удалить черновик"><x-ui.icon name="trash" class="size-5"/></x-ui.button></form>
            @endif
            <x-ui.button form="offer-form" variant="secondary" class="min-w-0 flex-1" data-save-bar-button data-save-bar="offer-form" hidden>Сохранить изменения</x-ui.button>
            <x-ui.button form="offer-form" name="then" value="next" class="min-w-0 flex-1"><x-ui.icon name="plus" class="size-5"/>Новый</x-ui.button>
        @elseif ($draft)
            <x-ui.button form="offer-form" variant="secondary" class="min-w-0 flex-1" data-save-bar-button data-save-bar="offer-form" hidden>Сохранить изменения</x-ui.button>
            {{-- Сначала поля, потом публикация (`then=open`) — сейчас или в слот: шторка с выбором, по умолчанию ближайший. --}}
            <div class="contents" data-controller="sheet">
                <x-ui.button type="button" class="min-w-0 flex-1" data-action="sheet#open">Опубликовать</x-ui.button>
                <x-offer.publish-sheet :offer="$offer" id="offer-publish" form="offer-form" :submit="['then' => 'open']"/>
            </div>
        @else
            <x-ui.button form="offer-form" class="min-w-0 flex-1" data-save-bar-button data-save-bar="offer-form" hidden>Сохранить изменения</x-ui.button>
        @endif
        @if (($transitions->isNotEmpty() || $publishItems || ($deletable && $admin)) && ! $fromMail)
            <x-ui.button type="button" variant="secondary" round class="btn-lg" data-action="sheet#open" aria-label="Состояние"><x-ui.icon name="more" class="size-6"/></x-ui.button>
            <x-ui.sheet id="offer-actions" title="Предложение № {{ $n }}">
                <div class="flex flex-col gap-2">
                    @if ($publishItems)<x-offer.publish-items :offer="$offer" button/>@endif
                    @foreach ($transitions as [$next, $label])
                        <form method="post" action="/offers/{{ $n }}/state" @if (in_array($next, [OfferState::Archived, OfferState::Cancelled])) data-turbo-confirm="{{ $next->label() }}?" @endif>
                            @csrf<input type="hidden" name="state" value="{{ $next->value }}">
                            <x-ui.button block :variant="$next === OfferState::Open ? 'primary' : ($next->tone() === 'danger' || $next === OfferState::Archived ? 'danger' : 'secondary')">{{ $label }}</x-ui.button>
                        </form>
                    @endforeach
                    @if ($canGarage)
                        <x-ui.button type="button" variant="secondary" block data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="garage:open">Отдать в гараж</x-ui.button>
                    @endif
                    @if ($deletable && $admin)
                        <form method="post" action="/offers/{{ $n }}" data-turbo-confirm="Удалить черновик? Фото и документы удалятся вместе с ним">@csrf @method('delete')<x-ui.button block variant="danger">Удалить черновик</x-ui.button></form>
                    @endif
                </div>
            </x-ui.sheet>
        @endif
    </x-ui.action-bar>
</x-ui.shell>
