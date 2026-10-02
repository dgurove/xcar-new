@php
    use App\Cars\{Body, Transmission, Drive, Fuel, DamageCause, DamageZone, Papers};
    use App\Offers\{OfferState, BidState, InterestState};
    $n = $offer->number;
    // «В гараже» ставит не кнопка состояния, а «Отдать в гараж»: там выбирают менеджера и цену.
    // Кнопки состояния — только уместные (OfferState::actions): в сделке меню нет, «Снять с продажи» — у того, что в продаже.
    $transitions = collect($offer->state->actions())->mapWithKeys(fn ($label, $state) => [$state => [OfferState::from($state), $label]]);
    $garage = $offer->state === OfferState::Garage ? \App\Garage\Car::with('manager')->where('offer_id', $offer->id)->first() : null;
    // Машину из гаража уводит только «Отдали по ошибке» там же: кнопки состояния тут отбились бы ошибкой.
    if ($garage) $transitions = collect();
    // Подтверждения: ждущие по сумме вниз, потом решённые.
    $bids = $offer->bids->sortBy([fn ($a, $b) => ($a->state === BidState::Active ? 0 : 1) <=> ($b->state === BidState::Active ? 0 : 1), ['amount', 'desc']]);
    $waiting = $offer->bids->where('state', BidState::Active);
    $grid = 'grid grid-cols-2 gap-3 @4xl:grid-cols-3';
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Предложения', '/']" cache="no-cache">
    <div class="-mt-3 mb-4 flex flex-wrap items-center gap-1.5">
        <span class="order-last ml-auto flex items-center gap-1">
            @include('admin.offers.share-button')
        </span>
        {{-- Номер предложения и номер ДЛ или убытка копируются нажатием, как VIN. --}}
        @unless ($offer->state === OfferState::Draft)<span class="tag nums gap-1">№<x-ui.copy-code :value="(string) $n" done="Номер в буфере"/></span>@endunless
        @if ($offer->claim_ref)<span class="tag nums gap-1">{{ $offer->leaseRef() ? 'ДЛ' : 'Убыток' }}<x-ui.copy-code :value="$offer->claim_ref"/></span>@endif
        @if ($garage)
            <x-ui.pill tone="plain" :href="$garage->url()" data-turbo="false">В гараже, {{ $garage->manager?->shortName() ?? 'взяли под себя' }}</x-ui.pill>
        @else
            {{-- В сделке пилюля состояния и есть вход в сделку: «Идёт сделка ›», второй пилюли «Сделка» рядом нет. --}}
            @if ($offer->deal)<x-ui.pill :tone="$offer->state->tone()" href="/work/deals/{{ $offer->deal->id }}">{{ $offer->state->label() }} ›</x-ui.pill>
            @else<x-ui.pill :tone="$offer->state->tone()">{{ $offer->state->label() }}</x-ui.pill>@endif
        @endif
        @if ($offer->closed())
            <x-ui.pill tone="closed">Приём закрыт с {{ $offer->bids_close_at->translatedFormat('j M, H:i') }}</x-ui.pill>
        @elseif ($offer->bids_close_at && $offer->state === OfferState::Open)
            <x-ui.pill tone="plain"><span class="nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}"></span></x-ui.pill>
        @endif
        @if ($offer->state === OfferState::Open && $offer->bids_close_at)
            {{-- Продлить приём на ходу, как в закупке: от текущего срока, если он не прошёл, иначе от сейчас. --}}
            <span class="flex shrink-0 items-center gap-1.5">
                @foreach ([15 => '+15 мин', 60 => '+1 ч'] as $minutes => $label)
                    <form method="post" action="/offers/{{ $n }}/extend" class="contents">@csrf<input type="hidden" name="minutes" value="{{ $minutes }}"><button class="pill pill-plain nums">{{ $label }}</button></form>
                @endforeach
            </span>
        @endif
        {{-- Письмо, документы и фото — шторкой рядом с полями (x-ui.docs); переписка — карточкой «Письма» и окном. --}}
        @if ($docs)<x-ui.docs-pill :docs="$docs"/>@endif
        @if ($chats->isNotEmpty())<x-ui.pill :tone="$chats->sum('unread_for_staff') ? 'urgent' : 'plain'" href="/work/chats?preset=all&q={{ $offer->number }}"><x-ui.icon name="chat" class="size-4"/> {{ $chats->count() === 1 ? 'Чат' : 'Чатов: '.$chats->count() }}@if ($chats->sum('unread_for_staff')) <span class="badge">{{ $chats->sum('unread_for_staff') }}</span>@endif</x-ui.pill>@endif
        @if ($import)<x-ui.pill tone="urgent">{{ $import['stage'] }}{{ isset($import['n']) ? ' '.($import['i'] + 1).'/'.$import['n'] : '' }}</x-ui.pill>@endif
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

            @if ($offer->positions->isNotEmpty())
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

            @if ($offer->interests->isNotEmpty())
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

            {{-- Кому показывать: сводка волн, правка в шторке. Поля живут в форме оффера через form=. --}}
            <x-ui.card title="Кому показывать" class="order-5">
                @include('admin.offers.fields.audience', ['form' => 'offer-form'])
                @if ($showingSummary->isNotEmpty())
                    <div class="mt-4 flex flex-col gap-1.5 text-sm">
                        @foreach ($showingSummary as $row)
                            <div class="flex items-center gap-2"><x-ui.person :user="$row['manager']"/><span class="text-ink-muted">открыл {{ $row['buyers'] }} {{ \App\Support\Plural::of($row['buyers'], ['покупателю', 'покупателям', 'покупателям']) }}</span></div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="История" class="order-6">
                <div class="flex flex-col gap-3 text-sm">
                    @foreach ($offer->events->take(30) as $event)
                        {{-- Как история дела ТС: сверху мелко дата, ниже текст во всю ширину, справа кто — в узкой колонке строка не ломается лесенкой. --}}
                        <div>
                            <div class="nums text-xs text-ink-dim">{{ $event->created_at->translatedFormat('j M, H:i') }}</div>
                            <div class="mt-0.5 flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1 leading-snug">{{ $event->text() }}</div>
                                @if ($event->user)<span class="shrink-0 text-ink-muted">{{ $event->user->shortName() }}</span>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        <form method="post" action="/offers/{{ $n }}" id="offer-form" data-controller="vin draft next" class="contents @4xl:col-start-1 @4xl:row-start-1 @4xl:flex @4xl:flex-col @4xl:gap-4">
            @csrf @method('put')

            <x-ui.card title="Транспортное средство" class="order-2">
                @include('admin.offers.fields.car')
            </x-ui.card>

            <x-ui.card title="Деньги" class="order-2">
                @include('admin.offers.fields.money')
            </x-ui.card>

        </form>

        <x-ui.card title="Фотографии" class="order-3 @4xl:col-span-2" data-controller="photos" data-photos-url-value="/offers/{{ $n }}/media">
            @include('admin.offers.photo-upload')
            @include('admin.offers.gallery')
        </x-ui.card>

        <x-ui.card title="Документы" class="order-4 @4xl:col-span-2">
            @include('admin.offers.papers-block')
        </x-ui.card>

        {{-- Письма — под документами, как в деле ТС: последнее словами, вся переписка и ответ — окном поверх редактора. --}}
        @if ($lastLetter)
            <x-ui.card title="Письма" :count="$letters" class="order-4 @4xl:col-span-2">
                <x-mail.last-letter :message="$lastLetter" :count="$letters" :url="'/offers/'.$n.'/letters'" :asks="$asks"/>
            </x-ui.card>
        @endif
    </div>
    </div>

    {{-- Гараж: машина уходит из продажи менеджеру на ремонт — нужен человек и цена, поэтому своя шторка. --}}
    @if (! $garage && $offer->state->allows(OfferState::Garage))
        <div data-controller="sheet" data-action="garage:open@window->sheet#open" class="contents">
            <x-ui.sheet id="offer-garage" title="Отдать в гараж">
                <form method="post" action="/offers/{{ $n }}/garage" class="flex flex-col gap-4">
                    @csrf
                    <x-ui.field name="manager_id" label="Кому" :options="$managers->pluck('name', 'id')" placeholder="Взяли под себя"/>
                    <x-ui.field name="cost" label="Отдали за, ₽" :value="$offer->floor_price"/>
                    <x-ui.button type="submit" variant="primary" block>Отдать в гараж</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
    @endif

    {{-- Черновик из писем до первого сохранения: «Отменить» (черновика не было, цепочка снова в «Из писем»), «Не заявка»
         (и цепочку в архив), «Сохранить». После сохранения у черновика — «Опубликовать» той же формой (`then=open`):
         поля сохраняются, потом публикация; из «···» она уходит, чтобы не стоять дважды. --}}
    @php
        $draft = $offer->state === OfferState::Draft;
        if ($draft) $transitions = $transitions->except(OfferState::Open->value);
    @endphp
    <x-mail.window :url="$window" :title="$offer->titleWithYear()"/>
    <x-ui.action-bar data-controller="sheet">
        @if ($fromMail)
            <form method="post" action="/offers/{{ $n }}/drop" class="contents">@csrf<x-ui.button variant="ghost" class="shrink-0 px-3 sm:px-7">Отменить</x-ui.button></form>
            <form method="post" action="/offers/{{ $n }}/drop" class="contents" data-turbo-confirm="Не заявка? Цепочка уйдёт в архив">@csrf<input type="hidden" name="decline" value="1"><x-ui.button variant="ghost" class="shrink-0 px-3 sm:px-7">Не заявка</x-ui.button></form>
            <x-ui.button form="offer-form" class="min-w-0 flex-1">Сохранить</x-ui.button>
        @elseif ($draft)
            <x-ui.button form="offer-form" variant="secondary" class="min-w-0 flex-1">Сохранить</x-ui.button>
            <x-ui.button form="offer-form" name="then" value="open" class="min-w-0 flex-1">Опубликовать</x-ui.button>
        @else
            <x-ui.button form="offer-form" class="min-w-0 flex-1">Сохранить</x-ui.button>
        @endif
        @if ($transitions->isNotEmpty() && ! $fromMail)
            <x-ui.button type="button" variant="secondary" round class="btn-lg" data-action="sheet#open" aria-label="Состояние"><x-ui.icon name="more" class="size-6"/></x-ui.button>
            <x-ui.sheet id="offer-actions" title="Предложение № {{ $n }}">
                <div class="flex flex-col gap-2">
                    @foreach ($transitions as [$next, $label])
                        <form method="post" action="/offers/{{ $n }}/state" @if (in_array($next, [OfferState::Archived, OfferState::Cancelled])) data-turbo-confirm="{{ $next->label() }}?" @endif>
                            @csrf<input type="hidden" name="state" value="{{ $next->value }}">
                            <x-ui.button block :variant="$next === OfferState::Open ? 'primary' : ($next->tone() === 'danger' || $next === OfferState::Archived ? 'danger' : 'secondary')">{{ $label }}</x-ui.button>
                        </form>
                    @endforeach
                    @if (! $garage && $offer->state->allows(OfferState::Garage))
                        <x-ui.button type="button" variant="secondary" block data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="garage:open">Отдать в гараж</x-ui.button>
                    @endif
                </div>
            </x-ui.sheet>
        @endif
    </x-ui.action-bar>
</x-ui.shell>
