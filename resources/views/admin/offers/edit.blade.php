@php
    use App\Cars\{Body, Transmission, Drive, Fuel, DamageCause, DamageZone, Papers};
    use App\Offers\{OfferState, BidState, InterestState};
    $n = $offer->number;
    $transitions = collect(OfferState::cases())->filter(fn ($s) => $offer->state->allows($s) && $s !== OfferState::Delivered);
    // Подтверждения: ждущие по сумме вниз, потом решённые.
    $bids = $offer->bids->sortBy([fn ($a, $b) => ($a->state === BidState::Active ? 0 : 1) <=> ($b->state === BidState::Active ? 0 : 1), ['amount', 'desc']]);
    $waiting = $offer->bids->where('state', BidState::Active);
    $best = $waiting->sortByDesc('amount')->first();
    $grid = 'grid grid-cols-2 gap-3 lg:grid-cols-3';
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Предложения', '/']" cache="no-cache">
    <div class="-mt-3 mb-4 flex flex-wrap items-center gap-1.5">
        <span class="order-last ml-auto flex items-center gap-1">
            @if ($offer->visiblePhotos()->isNotEmpty() || $offer->asking_price)<x-offer.share :offer="$offer" icon/>@endif
        </span>
        <x-ui.pill :tone="$offer->state->tone()">{{ $offer->state->label() }}</x-ui.pill>
        @if ($offer->closed())
            <x-ui.pill tone="closed">Приём закрыт с {{ $offer->bids_close_at->translatedFormat('j M, H:i') }}</x-ui.pill>
        @elseif ($offer->bids_close_at && $offer->state === OfferState::Open)
            <x-ui.pill tone="plain"><span class="nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}"></span></x-ui.pill>
        @endif
        @if ($offer->state === OfferState::Open && $offer->bids_close_at)
            {{-- Продлить приём на ходу, как в закупке: от текущего срока, если он не прошёл, иначе от сейчас. --}}
            <span class="flex shrink-0 items-center gap-1.5">
                @foreach ([15 => '+15 мин', 60 => '+1 ч'] as $minutes => $label)
                    <form method="post" action="/predlozheniya/{{ $n }}/prodlit" class="contents">@csrf<input type="hidden" name="minutes" value="{{ $minutes }}"><button class="pill pill-plain nums">{{ $label }}</button></form>
                @endforeach
            </span>
        @endif
        @if ($threads->count() === 1)<x-ui.pill tone="plain" href="/rabota/pochta/{{ $threads->first()->id }}"><x-ui.icon name="mail" class="size-4"/> Переписка</x-ui.pill>
        @elseif ($threads->isNotEmpty())<x-ui.pill tone="plain" href="/rabota/pochta?preset=linked&q={{ urlencode($offer->claim_ref ?: '') }}"><x-ui.icon name="mail" class="size-4"/> Переписок: {{ $threads->count() }}</x-ui.pill>@endif
        @if ($offer->deal)<x-ui.pill tone="open" href="/rabota/sdelki/{{ $offer->deal->id }}"><x-ui.icon name="deal" class="size-4"/> Сделка</x-ui.pill>@endif
        @if ($chats->isNotEmpty())<x-ui.pill :tone="$chats->sum('unread_for_staff') ? 'urgent' : 'plain'" href="/rabota/chaty?preset=all&q={{ $offer->number }}"><x-ui.icon name="chat" class="size-4"/> {{ $chats->count() === 1 ? 'Чат' : 'Чатов: '.$chats->count() }}@if ($chats->sum('unread_for_staff')) <span class="badge">{{ $chats->sum('unread_for_staff') }}</span>@endif</x-ui.pill>@endif
        @if ($import)<x-ui.pill tone="urgent">{{ $import['stage'] }}{{ isset($import['n']) ? ' '.($import['i'] + 1).'/'.$import['n'] : '' }}</x-ui.pill>@endif
        @if ($errors->has('state'))<x-ui.flash tone="danger" class="w-full">{{ $errors->first('state') }}</x-ui.flash>@endif
    </div>

    {{-- На телефоне блоки идут в одну колонку по order-*, на десктопе обёртки становятся колонками. --}}
    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="contents lg:col-start-2 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            @if ($bids->isNotEmpty())
            <x-ui.card :title="'Подтверждения'.($waiting->isNotEmpty() ? ' '.$waiting->count() : '')" class="order-1 {{ $waiting->isNotEmpty() ? 'box-urgent' : '' }}">
                <div class="flex flex-col gap-2">
                    @foreach ($bids as $bid)
                        <div class="box-nested">
                            <div class="flex items-center gap-2">
                                <span class="nums whitespace-nowrap text-lg">{{ number_format($bid->amount, 0, '', ' ') }} ₽</span>
                                @if ($bid->is($best) && $waiting->count() > 1)<x-ui.pill tone="soft" class="!min-h-0 !py-1 text-xs">Лучшая</x-ui.pill>
                                @elseif ($bid->state !== BidState::Active)<x-ui.pill :tone="$bid->state === BidState::Accepted ? 'open' : ($bid->state === BidState::Declined ? 'danger' : 'closed')" class="!min-h-0 !py-1 text-xs">{{ $bid->state->label() }}</x-ui.pill>@endif
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5"><x-ui.person :user="$bid->user" full/><a href="tel:+{{ $bid->user->phone }}" class="tag nums">{{ $bid->user->phoneFormatted() }}</a><span class="tag nums">{{ $bid->created_at->translatedFormat('j M, H:i') }}</span></div>
                            @if ($bid->comment)<div class="mt-1 text-sm">{{ $bid->comment }}</div>@endif
                            @if ($bid->state === BidState::Active)
                                <div class="mt-2 flex gap-2">
                                    <form method="post" action="/stavki/{{ $bid->id }}/prinyat" data-turbo-confirm="Отдать {{ $offer->title() }} менеджеру {{ $bid->user->name }} за {{ number_format($bid->amount, 0, '', ' ') }} ₽?{{ $waiting->count() > 1 ? ' Остальные подтверждения будут отклонены.' : '' }}">@csrf<x-ui.button size="sm">Принять</x-ui.button></form>
                                    <form method="post" action="/stavki/{{ $bid->id }}/otklonit">@csrf<x-ui.button size="sm" variant="ghost">Отклонить</x-ui.button></form>
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
                        <a href="/rabota/chaty/{{ $chat->id }}" class="flex items-center gap-3 py-2">
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
                                <form method="post" action="/interesy/{{ $interest->id }}" class="mt-2">@csrf<input type="hidden" name="state" value="contacted"><x-ui.button size="sm" variant="secondary">Связались</x-ui.button></form>
                            @elseif ($interest->state === InterestState::Contacted)
                                <form method="post" action="/interesy/{{ $interest->id }}" class="mt-2">@csrf<input type="hidden" name="state" value="closed"><x-ui.button size="sm" variant="ghost">Закрыть</x-ui.button></form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
            @endif

            {{-- Круг менеджеров: по умолчанию все; сняли «Все» — выбирайте. Поля живут в форме оффера через form=. --}}
            <x-ui.card title="Менеджеры" class="order-5" data-controller="managers">
                <input type="hidden" name="managers_limited" value="{{ old('managers_limited', $offer->managers_limited ? 1 : 0) }}" form="offer-form" data-managers-target="limited">
                <div class="flex flex-wrap gap-1.5">
                    <label class="choice"><input type="checkbox" @checked(! old('managers_limited', $offer->managers_limited)) data-managers-target="all" data-action="managers#toggleAll"><span>Все</span></label>
                    @foreach ($managers as $m)
                        <label class="choice"><input type="checkbox" name="managers[]" value="{{ $m->id }}" form="offer-form" @checked(in_array($m->id, old('managers', $offerManagers))) @disabled(! old('managers_limited', $offer->managers_limited)) data-managers-target="chip" data-action="managers#pick"><span class="gap-1.5"><x-ui.avatar :user="$m" :size="20"/>{{ $m->shortName() }}</span></label>
                    @endforeach
                </div>
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

        <form method="post" action="/predlozheniya/{{ $n }}" id="offer-form" data-controller="vin draft" class="contents lg:col-start-1 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            @csrf @method('put')

            <x-ui.card title="Машина" class="order-2">
                <div class="{{ $grid }}">
                    <x-ui.combobox name="brand_id" label="Марка" url="/spravochnik/marki" create="/spravochnik/marki" :value="$offer->brand_id" :text="$offer->brand?->name" resets="#cb-model_id"/>
                    <x-ui.combobox name="model_id" label="Модель" url="/spravochnik/modeli" create="/spravochnik/modeli" depends="#f-brand_id" :value="$offer->model_id" :text="$offer->model?->name"/>
                    <x-ui.vin :value="$offer->vin" span="col-span-2 lg:col-span-1">
                        <x-slot:after-label><x-ui.eye-check name="show_vin" :checked="$offer->show_vin"/></x-slot:after-label>
                    </x-ui.vin>
                    <x-ui.field name="year" label="Год" inputmode="numeric" :value="$offer->year"/>
                    <x-ui.field name="mileage" label="Пробег, км" inputmode="numeric" :value="$offer->mileage"/>
                    <x-ui.field name="color" label="Цвет" :value="$offer->color"/>
                    <x-ui.field name="body" label="Кузов" :options="Body::options()" placeholder="—" :value="$offer->body?->value"/>
                    <x-ui.field name="transmission" label="Коробка" :options="Transmission::options()" placeholder="—" :value="$offer->transmission?->value"/>
                    <x-ui.field name="drive" label="Привод" :options="Drive::options()" placeholder="—" :value="$offer->drive?->value"/>
                    <x-ui.field name="fuel" label="Топливо" :options="Fuel::options()" placeholder="—" :value="$offer->fuel?->value"/>
                    <x-ui.field name="engine_volume" label="Объём, см³" inputmode="numeric" :value="$offer->engine_volume"/>
                    <x-ui.field name="engine_power" label="Мощность, л. с." inputmode="numeric" :value="$offer->engine_power"/>
                    <x-ui.field name="settlement_id" label="Город" :options="$settlements" placeholder="—" :value="$offer->settlement_id"/>
                    <x-ui.field name="inspection_address" label="Адрес осмотра" :value="$offer->inspection_address" span="col-span-2"/>
                </div>
            </x-ui.card>

            <x-ui.card title="Состояние" class="order-2">
                <div class="{{ $grid }}">
                    <x-ui.field name="damage_cause" label="Причина" :options="DamageCause::options()" placeholder="—" :value="$offer->damage_cause?->value"/>
                    <x-ui.field name="incident_date" label="Дата события" type="date" :value="$offer->incident_date?->toDateString()"/>
                    <x-ui.field name="papers" label="Документы" :options="Papers::options()" placeholder="—" :value="$offer->papers?->value" span="col-span-2 lg:col-span-1"/>
                    <div class="field col-span-full">
                        <span class="field-label">Повреждения</span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (DamageZone::cases() as $zone)
                                <label class="choice"><input type="checkbox" name="damage_zones[]" value="{{ $zone->value }}" @checked(in_array($zone->value, old('damage_zones', $offer->damage_zones ?? [])))><span>{{ $zone->label() }}</span></label>
                            @endforeach
                        </div>
                    </div>
                    <x-ui.tri name="is_runnable" label="На ходу" :value="$offer->is_runnable"/>
                    <x-ui.tri name="has_keys" label="Ключи" :value="$offer->has_keys"/>
                    <x-ui.field name="description" label="Описание" type="textarea" :value="$offer->description" span="col-span-full"/>
                </div>
            </x-ui.card>

            <x-ui.card title="Деньги" class="order-2">
                <div class="{{ $grid }}">
                    <x-ui.field name="floor_price" label="Закупочная, ₽" inputmode="numeric" :value="$offer->floor_price"/>
                    <x-ui.field name="publish_price" label="Заявленная, ₽" inputmode="numeric" :value="$offer->publish_price" :placeholder="$offer->floor_price ? number_format($offer->floor_price, 0, '', ' ') : null"/>
                    <x-ui.field name="asking_price" label="Цена продажи, ₽" inputmode="numeric" :value="$offer->asking_price"/>
                    <x-ui.field name="min_bid_price" label="Минимальная, ₽" inputmode="numeric" :value="$offer->min_bid_price" :placeholder="$offer->minBid() ? number_format($offer->minBid(), 0, '', ' ') : null"/>
                    <x-ui.field name="min_bid_share" label="Доля до продажной" inputmode="decimal" :value="$offer->min_bid_share" placeholder="0,6"/>
                    <x-ui.field name="bids_close_at" label="Приём подтверждений до" type="datetime-local" :value="$offer->bids_close_at?->format('Y-m-d\TH:i')" span="col-span-2 lg:col-span-1"/>
                    <div class="col-span-full flex flex-wrap items-center gap-x-6 gap-y-2 pt-1">
                        <x-ui.check name="prices_include_vat" :checked="$offer->prices_include_vat">С НДС</x-ui.check>
                        <x-ui.check name="chat_enabled" :checked="$offer->chat_enabled">Чат с покупателями</x-ui.check>
                        <x-ui.check name="share_locked" :checked="$offer->share_locked">Запретить шеринг</x-ui.check>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="Страховая" class="order-2">
                <div class="{{ $grid }}">
                    <x-ui.field name="insurer_id" label="Страховая" :options="$insurers" placeholder="—" :value="$offer->insurer_id"/>
                    <x-ui.field name="claim_ref" label="Номер убытка" :value="$offer->claim_ref"/>
                    <x-ui.field name="insurer_deadline_at" label="Продать до" type="date" :value="$offer->insurer_deadline_at?->toDateString()" span="col-span-2 lg:col-span-1"/>
                    @if ($tags->isNotEmpty())
                        <div class="field col-span-full">
                            <span class="field-label">Метки</span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($tags as $tag)
                                    <label class="choice"><input type="checkbox" name="tags[]" value="{{ $tag->name }}" @checked(in_array($tag->name, old('tags', $offer->tags ?? [])))><span>{{ $tag->name }}</span></label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </x-ui.card>
        </form>

        <x-ui.card title="Фотографии" class="order-3 lg:col-span-2" data-controller="photos" data-photos-url-value="/predlozheniya/{{ $n }}/media">
            <input type="file" accept="image/*,.heic,.heif" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div hidden data-photos-target="progress" class="mb-3">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('admin.offers.gallery')
        </x-ui.card>

        <x-ui.card title="Документы" class="order-4 lg:col-span-2" data-controller="photos" data-photos-url-value="/predlozheniya/{{ $n }}/media" data-photos-collection-value="papers">
            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div class="mb-2"><x-ui.button type="button" variant="secondary" size="sm" data-action="photos#pick"><x-ui.icon name="plus" class="size-4"/> Добавить документ</x-ui.button></div>
            <div hidden data-photos-target="progress" class="mb-3">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('admin.offers.papers')
        </x-ui.card>
    </div>

    <x-ui.action-bar data-controller="sheet">
        <x-ui.button form="offer-form" class="min-w-0 flex-1">Сохранить</x-ui.button>
        @if ($transitions->isNotEmpty())
            <x-ui.button type="button" variant="secondary" round class="btn-lg" data-action="sheet#open" aria-label="Состояние"><x-ui.icon name="more" class="size-6"/></x-ui.button>
            <x-ui.sheet id="offer-actions" title="Предложение № {{ $n }}">
                <div class="flex flex-col gap-2">
                    @foreach ($transitions as $next)
                        <form method="post" action="/predlozheniya/{{ $n }}/sostoyanie" @if (in_array($next, [OfferState::Archived, OfferState::Cancelled])) data-turbo-confirm="{{ $next->label() }}?" @endif>
                            @csrf<input type="hidden" name="state" value="{{ $next->value }}">
                            <x-ui.button block :variant="$next === OfferState::Open ? 'primary' : ($next->tone() === 'danger' || $next === OfferState::Archived ? 'danger' : 'secondary')">{{ match($next) {
                                OfferState::Open => 'Опубликовать',
                                OfferState::Gallery => 'В галерею «скоро»', OfferState::Draft => 'В черновик',
                                OfferState::Sold => 'В сделку', OfferState::Cancelled => 'Снять с продажи', OfferState::Archived => 'В архив', default => $next->label() } }}</x-ui.button>
                        </form>
                    @endforeach
                </div>
            </x-ui.sheet>
        @endif
    </x-ui.action-bar>
</x-ui.shell>
