@php
    use App\Cars\{Body, Transmission, Drive, Fuel, DamageCause, DamageZone, Papers};
    use App\Offers\OfferState;
    $n = $offer->number;
    $transitions = collect(OfferState::cases())->filter(fn ($s) => $offer->state->allows($s) && $s !== OfferState::Delivered);
@endphp
<x-ui.shell :title="'№ '.$n.' · '.$offer->title()" back="/admin/offers" :wide="true">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-offer.state :state="$offer->state"/>
        @if ($offer->bids_close_at && $offer->state === OfferState::Open)
            <span class="chip" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}"></span>
        @endif
        @if ($offer->state->isPublic())<a href="/offers/{{ $n }}" class="chip">На сайте →</a>@endif
        @if ($errors->has('state'))<span class="field-error w-full">{{ $errors->first('state') }}</span>@endif
    </div>

    <div class="grid gap-4 lg:grid-cols-[1fr_380px]">
    <form method="post" action="/admin/offers/{{ $n }}" id="offer-form" class="flex flex-col gap-4">
        @csrf @method('put')

        <x-ui.card title="Машина">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.combobox name="brand_id" label="Марка" url="/admin/spravochnik/marki" create="/admin/spravochnik/marki" :value="$offer->brand_id" :text="$offer->brand?->name" resets="#cb-model_id"/>
                <x-ui.combobox name="model_id" label="Модель" url="/admin/spravochnik/modeli" create="/admin/spravochnik/modeli" depends="#f-brand_id" :value="$offer->model_id" :text="$offer->model?->name"/>
                <x-ui.field name="year" label="Год" inputmode="numeric" :value="$offer->year"/>
                <x-ui.field name="mileage" label="Пробег, км" inputmode="numeric" :value="$offer->mileage"/>
                <x-ui.field name="vin" label="VIN" :value="$offer->vin" maxlength="17" class="uppercase" autocapitalize="characters"/>
                <div class="flex items-end pb-3"><x-ui.check name="show_vin" :checked="$offer->show_vin">Показывать VIN на сайте</x-ui.check></div>
                <x-ui.field name="body" label="Кузов" :options="Body::options()" placeholder="—" :value="$offer->body?->value"/>
                <x-ui.field name="transmission" label="Коробка" :options="Transmission::options()" placeholder="—" :value="$offer->transmission?->value"/>
                <x-ui.field name="drive" label="Привод" :options="Drive::options()" placeholder="—" :value="$offer->drive?->value"/>
                <x-ui.field name="fuel" label="Топливо" :options="Fuel::options()" placeholder="—" :value="$offer->fuel?->value"/>
                <x-ui.field name="engine_volume" label="Объём, см³" inputmode="numeric" :value="$offer->engine_volume"/>
                <x-ui.field name="engine_power" label="Мощность, л. с." inputmode="numeric" :value="$offer->engine_power"/>
                <x-ui.field name="color" label="Цвет" :value="$offer->color"/>
            </div>
        </x-ui.card>

        <x-ui.card title="Состояние">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field name="damage_cause" label="Причина" :options="DamageCause::options()" placeholder="—" :value="$offer->damage_cause?->value"/>
                <x-ui.field name="incident_date" label="Дата события" type="date" :value="$offer->incident_date?->toDateString()"/>
                <div class="field sm:col-span-2">
                    <span class="field-label">Повреждения</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach (DamageZone::cases() as $zone)
                            <label><input type="checkbox" name="damage_zones[]" value="{{ $zone->value }}" class="peer sr-only" @checked(in_array($zone->value, old('damage_zones', $offer->damage_zones ?? [])))>
                                <span class="chip cursor-pointer select-none px-3 py-2 peer-checked:bg-chrome peer-checked:text-white dark:peer-checked:bg-white dark:peer-checked:text-chrome">{{ $zone->label() }}</span></label>
                        @endforeach
                    </div>
                </div>
                <x-ui.tri name="is_runnable" label="На ходу" :value="$offer->is_runnable"/>
                <x-ui.tri name="has_keys" label="Ключи" :value="$offer->has_keys"/>
                <x-ui.field name="papers" label="Документы" :options="Papers::options()" placeholder="—" :value="$offer->papers?->value"/>
                <x-ui.field name="description" label="Описание" type="textarea" :value="$offer->description" class="sm:col-span-2"/>
            </div>
        </x-ui.card>

        <x-ui.card title="Где">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field name="settlement_id" label="Город" :options="$settlements" placeholder="—" :value="$offer->settlement_id"/>
                <x-ui.field name="inspection_address" label="Адрес осмотра" :value="$offer->inspection_address"/>
            </div>
        </x-ui.card>

        <x-ui.card title="Деньги">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field name="floor_price" label="Закупочная, ₽" inputmode="numeric" :value="$offer->floor_price"/>
                <x-ui.field name="repair_estimate" label="Ремонт, ₽" inputmode="numeric" :value="$offer->repair_estimate"/>
                <x-ui.field name="asking_price" label="Цена продажи, ₽" inputmode="numeric" :value="$offer->asking_price"/>
                <x-ui.field name="min_bid_price" label="Нижняя граница ставки, ₽" inputmode="numeric" :value="$offer->min_bid_price" :placeholder="$offer->minBid() ? number_format($offer->minBid(), 0, '', ' ') : null"/>
                <x-ui.field name="min_bid_share" label="Доля до продажной" inputmode="decimal" :value="$offer->min_bid_share" placeholder="0,6"/>
                <x-ui.tri name="prices_include_vat" label="Цены с НДС" :value="$offer->prices_include_vat"/>
                <x-ui.field name="bids_close_at" label="Приём ставок до" type="datetime-local" :value="$offer->bids_close_at?->format('Y-m-d\TH:i')"/>
                <div class="flex items-end pb-3"><x-ui.check name="chat_enabled" :checked="$offer->chat_enabled">Чат с покупателями</x-ui.check></div>
            </div>
        </x-ui.card>

        @if ($tags->isNotEmpty())
        <x-ui.card title="Метки">
            <div class="flex flex-wrap gap-2">
                @foreach ($tags as $tag)
                    <label><input type="checkbox" name="tags[]" value="{{ $tag->name }}" class="peer sr-only" @checked(in_array($tag->name, old('tags', $offer->tags ?? [])))>
                        <span class="chip cursor-pointer select-none px-3 py-2 peer-checked:bg-chrome peer-checked:text-white dark:peer-checked:bg-white dark:peer-checked:text-chrome">{{ $tag->name }}</span></label>
                @endforeach
            </div>
        </x-ui.card>
        @endif
    </form>

    <div class="flex flex-col gap-4">
        <x-ui.card title="Фотографии" data-controller="photos" data-photos-url-value="/admin/offers/{{ $n }}/media">
            <input type="file" accept="image/*,.heic,.heif" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div class="mb-3 flex gap-2">
                <x-ui.button type="button" variant="secondary" size="sm" data-action="photos#pick"><x-ui.icon name="camera" class="size-4"/> Добавить фото</x-ui.button>
            </div>
            <div hidden data-photos-target="progress" class="mb-3">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('admin.offers.gallery')
        </x-ui.card>

        <x-ui.card title="Документы" data-controller="photos" data-photos-url-value="/admin/offers/{{ $n }}/media" data-photos-collection-value="papers">
            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div class="mb-3"><x-ui.button type="button" variant="secondary" size="sm" data-action="photos#pick"><x-ui.icon name="plus" class="size-4"/> Добавить документ</x-ui.button></div>
            <div hidden data-photos-target="progress" class="mb-3">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('admin.offers.papers')
        </x-ui.card>

        @if ($offer->bids->isNotEmpty())
        <x-ui.card title="Ставки">
            <div class="flex flex-col divide-y divide-line/40">
                @foreach ($offer->bids as $bid)
                    <div class="flex items-center gap-3 py-2.5">
                        <div class="flex-1">
                            <div class="flex items-baseline gap-2"><x-offer.price :amount="$bid->amount"/><span class="text-sm text-ink-muted">{{ $bid->state->label() }}</span></div>
                            <div class="text-sm text-ink-muted">{{ $bid->user->name }} · {{ $bid->user->phoneFormatted() }} · {{ $bid->created_at->translatedFormat('j M, H:i') }}</div>
                            @if ($bid->comment)<div class="text-sm">{{ $bid->comment }}</div>@endif
                        </div>
                        @if ($bid->state === \App\Offers\BidState::Active)
                            <form method="post" action="/admin/stavki/{{ $bid->id }}/prinyat" data-turbo-confirm="Принять ставку {{ number_format($bid->amount, 0, '', ' ') }} ₽ и открыть сделку?">@csrf<x-ui.button size="sm">Принять</x-ui.button></form>
                            <form method="post" action="/admin/stavki/{{ $bid->id }}/otklonit">@csrf<x-ui.button size="sm" variant="ghost">Отклонить</x-ui.button></form>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>
        @endif

        @if ($offer->interests->isNotEmpty())
        <x-ui.card title="Интерес">
            <div class="flex flex-col divide-y divide-line/40">
                @foreach ($offer->interests as $interest)
                    <div class="flex items-center gap-3 py-2.5">
                        <div class="flex-1">
                            <div>{{ $interest->user->name }} <a href="tel:+{{ $interest->user->phone }}" class="text-accent-text">{{ $interest->user->phoneFormatted() }}</a></div>
                            <div class="text-sm text-ink-muted">{{ $interest->state->label() }} · {{ $interest->created_at->translatedFormat('j M, H:i') }}</div>
                            @if ($interest->comment)<div class="text-sm">{{ $interest->comment }}</div>@endif
                        </div>
                        @if ($interest->state === \App\Offers\InterestState::New)
                            <form method="post" action="/admin/interesy/{{ $interest->id }}">@csrf<input type="hidden" name="state" value="contacted"><x-ui.button size="sm" variant="secondary">Связались</x-ui.button></form>
                        @elseif ($interest->state === \App\Offers\InterestState::Contacted)
                            <form method="post" action="/admin/interesy/{{ $interest->id }}">@csrf<input type="hidden" name="state" value="closed"><x-ui.button size="sm" variant="ghost">Закрыть</x-ui.button></form>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>
        @endif

        <x-ui.card title="История">
            <div class="flex flex-col gap-2 text-sm">
                @foreach ($offer->events->take(30) as $event)
                    <div class="flex gap-3">
                        <span class="shrink-0 tabular-nums text-ink-dim">{{ $event->created_at->translatedFormat('j M H:i') }}</span>
                        <span>{{ match($event->type) {
                            \App\Offers\OfferEventType::Created => 'Создан',
                            \App\Offers\OfferEventType::Updated => 'Изменён: '.implode(', ', $event->payload['fields'] ?? []),
                            \App\Offers\OfferEventType::StateChanged => OfferState::from($event->payload['to'])->label(),
                            \App\Offers\OfferEventType::BidPlaced => 'Ставка '.number_format($event->payload['amount'] ?? 0, 0, '', ' ').' ₽',
                            \App\Offers\OfferEventType::BidAccepted => 'Ставка принята',
                            \App\Offers\OfferEventType::BidDeclined => 'Ставка отклонена',
                            \App\Offers\OfferEventType::BidWithdrawn => 'Ставка отозвана',
                            \App\Offers\OfferEventType::Interest => 'Интерес',
                            default => $event->type->value } }}</span>
                        @if ($event->user)<span class="ml-auto text-ink-muted">{{ $event->user->name }}</span>@endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>
    </div>

    <div class="sticky-actions" data-controller="sheet">
        <x-ui.button form="offer-form" class="flex-1">Сохранить</x-ui.button>
        @if ($transitions->isNotEmpty())
            <x-ui.button type="button" variant="secondary" data-action="sheet#open"><x-ui.icon name="more" class="size-5"/></x-ui.button>
            <x-ui.sheet id="offer-actions" title="Оффер № {{ $n }}">
                <div class="flex flex-col gap-2">
                    @foreach ($transitions as $next)
                        <form method="post" action="/admin/offers/{{ $n }}/sostoyanie" @if (in_array($next, [OfferState::Archived, OfferState::Cancelled])) data-turbo-confirm="{{ $next->label() }}?" @endif>
                            @csrf<input type="hidden" name="state" value="{{ $next->value }}">
                            <x-ui.button block :variant="$next === OfferState::Open ? 'primary' : ($next->tone() === 'danger' || $next === OfferState::Archived ? 'danger' : 'secondary')">{{ match($next) {
                                OfferState::Open => $offer->state === OfferState::Closed ? 'Открыть приём снова' : 'Опубликовать',
                                OfferState::Gallery => 'В галерею «скоро»', OfferState::Draft => 'В черновик', OfferState::Closed => 'Закрыть приём ставок',
                                OfferState::Sold => 'В сделку', OfferState::Cancelled => 'Снять с продажи', OfferState::Archived => 'В архив', default => $next->label() } }}</x-ui.button>
                        </form>
                    @endforeach
                </div>
            </x-ui.sheet>
        @endif
    </div>
</x-ui.shell>
