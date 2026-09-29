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
    $best = $waiting->sortByDesc('amount')->first();
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
            <x-ui.pill tone="plain" :href="\App\Support\Surface::Garage->url('/cars/'.$n)" data-turbo="false">В гараже, {{ $garage->manager?->shortName() ?? 'взяли под себя' }} ↗</x-ui.pill>
        @else
            <x-ui.pill :tone="$offer->state->tone()">{{ $offer->state->label() }}</x-ui.pill>
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
        {{-- Письмо, документы и фото — шторкой рядом с полями (x-ui.docs), переписка целиком — ссылкой из письма. --}}
        @if ($docs)<x-ui.docs-pill :docs="$docs"/>
        @elseif ($threads->count() === 1)<x-ui.pill tone="plain" href="/work/mail/{{ $threads->first()->id }}"><x-ui.icon name="mail" class="size-4"/> Переписка</x-ui.pill>
        @elseif ($threads->isNotEmpty())<x-ui.pill tone="plain" href="/work/mail?preset=linked&q={{ urlencode($offer->claim_ref ?: '') }}"><x-ui.icon name="mail" class="size-4"/> Переписок: {{ $threads->count() }}</x-ui.pill>@endif
        @if ($offer->deal)<x-ui.pill tone="open" href="/work/deals/{{ $offer->deal->id }}"><x-ui.icon name="deal" class="size-4"/> Сделка</x-ui.pill>@endif
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
            <x-ui.card :title="'Подтверждения'.($waiting->isNotEmpty() ? ' '.$waiting->count() : '')" class="order-1 {{ $waiting->isNotEmpty() ? 'box-urgent' : '' }}">
                <div class="flex flex-col gap-2">
                    @foreach ($bids as $bid)
                        <div class="box-nested">
                            <div class="flex items-center gap-2">
                                <span class="nums whitespace-nowrap text-lg font-semibold">{{ \App\Support\Money::rub($bid->amount) }}</span>
                                @if ($bid->is($best) && $waiting->count() > 1)<x-ui.pill tone="soft" class="!min-h-0 !py-1 text-xs">Лучшая</x-ui.pill>
                                @elseif ($bid->state !== BidState::Active)<x-ui.pill :tone="$bid->state === BidState::Accepted ? 'open' : ($bid->state === BidState::Declined ? 'danger' : 'closed')" class="!min-h-0 !py-1 text-xs">{{ $bid->state->label() }}</x-ui.pill>@endif
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5"><x-ui.person :user="$bid->user" full/><a href="tel:+{{ $bid->user->phone }}" class="tag nums">{{ $bid->user->phoneFormatted() }}</a><span class="tag nums">{{ $bid->created_at->translatedFormat('j M, H:i') }}</span></div>
                            @if ($bid->comment)<div class="mt-1 text-sm">{{ $bid->comment }}</div>@endif
                            @if ($bid->state === BidState::Active)
                                <div class="mt-2 flex gap-2">
                                    <div data-controller="sheet">
                                        <x-ui.button type="button" size="sm" data-action="sheet#open">{{ $offer->deal ? 'Отдать' : 'Принять' }}</x-ui.button>
                                        <x-ui.sheet id="accept-{{ $bid->id }}" :title="$offer->deal ? 'Отдать другому' : 'Принять подтверждение'" :open="$errors->has('commission') && old('bid') == $bid->id">
                                            <div class="mb-4 flex flex-wrap items-center gap-1.5"><x-ui.person :user="$bid->user" full/><span class="tag">{{ $offer->titleWithYear() }}</span></div>
                                            <x-offer.money-form :action="'/confirmations/'.$bid->id.'/accept'" :amount="$bid->amount" :cost="$offer->floor_price" :submit="$offer->deal ? 'Отдать' : 'Принять'" :note="$offer->deal ? 'Сделка с '.$offer->deal->buyer?->shortName().' отменится' : null"><input type="hidden" name="bid" value="{{ $bid->id }}"></x-offer.money-form>
                                        </x-ui.sheet>
                                    </div>
                                    <form method="post" action="/confirmations/{{ $bid->id }}/decline">@csrf<x-ui.button size="sm" variant="ghost">Отклонить</x-ui.button></form>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
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
                <div class="flex flex-col gap-1.5 text-sm">
                    @foreach ($offer->events->take(30) as $event)
                        <div class="flex gap-3">
                            <span class="shrink-0 text-ink-dim">{{ $event->created_at->translatedFormat('j M H:i') }}</span>
                            <span class="min-w-0">{{ $event->text() }}</span>
                            @if ($event->user)<span class="ml-auto shrink-0 text-ink-muted">{{ $event->user->shortName() }}</span>@endif
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

            <x-ui.card title="Состояние" class="order-2">
                @include('admin.offers.fields.condition')
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

    <x-ui.action-bar data-controller="sheet">
        <x-ui.button form="offer-form" class="min-w-0 flex-1">Сохранить</x-ui.button>
        @if ($transitions->isNotEmpty())
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
