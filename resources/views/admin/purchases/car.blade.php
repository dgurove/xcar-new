@php use App\Cars\{Transmission, Fuel}; use App\Purchases\{Kind, OfferState, ImportState}; $n = $purchase->number; @endphp
<x-ui.shell :title="$car->titleWithYear()">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <span class="chip">{{ str_starts_with(mb_strtoupper($car->dl), 'ДЛ') ? $car->dl : 'ДЛ '.$car->dl }}</span>
        <span class="chip">№ {{ $car->ref }}</span>
        @if ($car->site_url)<a href="{{ $car->site_url }}" target="_blank" class="chip">Сайт поставщика ↗</a>@endif
        @if ($car->cloud_url)<a href="{{ $car->cloud_url }}" target="_blank" class="chip">Облако ↗</a>@endif
        @if ($purchase->state->isPublic())<a href="{{ \App\Support\Surface::Site->url("/zakupki/$n/{$car->ref}") }}" data-turbo="false" class="chip">На сайте →</a>@endif
        @if ($errors->any())<span class="field-error w-full">{{ $errors->first() }}</span>@endif
    </div>
    <div class="grid items-start gap-4 lg:grid-cols-[1fr_380px]">
        <form method="post" action="/zakupki/{{ $n }}/{{ $car->ref }}" id="car-form" class="flex flex-col gap-4">
            @csrf @method('put')
            <x-ui.card title="Машина">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.combobox name="brand_id" label="Марка" url="/spravochnik/marki" create="/spravochnik/marki" :value="$car->brand_id" :text="$car->brand?->name ?? $car->brand_raw" resets="#cb-model_id"/>
                    <x-ui.combobox name="model_id" label="Модель" url="/spravochnik/modeli" create="/spravochnik/modeli" depends="#f-brand_id" :value="$car->model_id" :text="$car->model?->name ?? $car->model_raw"/>
                    <x-ui.field name="year" label="Год" inputmode="numeric" :value="$car->year"/>
                    <x-ui.field name="mileage" label="Пробег, км" inputmode="numeric" :value="$car->mileage"/>
                    <x-ui.field name="vin" label="VIN" :value="$car->vin" maxlength="17" class="uppercase"/>
                    <x-ui.field name="kind" label="Категория" :options="Kind::options()" :value="$car->kind->value"/>
                    <x-ui.field name="transmission" label="Коробка" :options="Transmission::options()" placeholder="—" :value="$car->transmission?->value"/>
                    <x-ui.field name="fuel" label="Топливо" :options="Fuel::options()" placeholder="—" :value="$car->fuel?->value"/>
                    <x-ui.field name="engine_volume" label="Объём, см³" inputmode="numeric" :value="$car->engine_volume"/>
                    <x-ui.field name="engine_power" label="Мощность, л. с." inputmode="numeric" :value="$car->engine_power"/>
                    <x-ui.field name="color" label="Цвет" :value="$car->color"/>
                    <x-ui.field name="condition" label="Состояние" :value="$car->condition"/>
                    <x-ui.field name="settlement_id" label="Город" :options="$settlements" placeholder="—" :value="$car->settlement_id"/>
                    <x-ui.field name="address" label="Адрес" :value="$car->address"/>
                    <x-ui.field name="description" label="Описание" type="textarea" :value="$car->description" class="sm:col-span-2"/>
                </div>
            </x-ui.card>
            <x-ui.card title="Цены поставщика">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field name="price_revalued" label="С учётом переоценки, ₽" inputmode="numeric" :value="$car->price_revalued"/>
                    <x-ui.field name="price_listing" label="Для размещения, ₽" inputmode="numeric" :value="$car->price_listing"/>
                    <div class="sm:col-span-2 text-sm text-ink-muted">{{ $car->encumbrance }}{{ $car->stage ? ' · '.$car->stage : '' }}</div>
                    <div class="flex items-end"><x-ui.check name="is_published" :checked="$car->is_published">Показывать покупателям</x-ui.check></div>
                </div>
            </x-ui.card>
        </form>
        <div class="flex flex-col gap-4">
            <x-ui.card title="Фотографии" data-controller="photos" data-photos-url-value="/zakupki/mashiny/{{ $car->id }}/media">
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <input type="file" accept="image/*,.heic" multiple hidden data-photos-target="input" data-action="change->photos#upload">
                    <x-ui.button type="button" variant="secondary" size="sm" data-action="photos#pick"><x-ui.icon name="camera" class="size-4"/> Добавить</x-ui.button>
                    @if ($car->cloud_url)
                        <form method="post" action="/zakupki/{{ $n }}/{{ $car->ref }}/zabrat">@csrf<input type="hidden" name="what" value="photos"><input type="hidden" name="all" value="1"><x-ui.button size="sm" variant="ghost">Забрать все из облака</x-ui.button></form>
                    @endif
                    <span class="chip {{ $car->photos_state->needsAttention() ? 'bg-urgent-soft text-urgent' : '' }}">{{ $car->photos_state->label() }}{{ $car->photos_error ? ': '.$car->photos_error : '' }}</span>
                </div>
                @if ($car->cloud_leftovers)<div class="mb-3 text-sm text-ink-muted">В облаке осталось: {{ collect($car->cloud_leftovers)->pluck('name')->implode(', ') }}</div>@endif
                <div hidden data-photos-target="progress" class="mb-3"><div class="mb-1 text-sm text-ink-muted" data-label></div><div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div></div>
                @include('admin.purchases.gallery')
            </x-ui.card>
            <x-ui.card title="Характеристики с сайта">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="chip {{ $car->specs_state->needsAttention() ? 'bg-urgent-soft text-urgent' : '' }}">{{ $car->specs_state->label() }}{{ $car->specs_error ? ': '.$car->specs_error : '' }}</span>
                    @if ($car->site_url)<form method="post" action="/zakupki/{{ $n }}/{{ $car->ref }}/zabrat">@csrf<input type="hidden" name="what" value="specs"><x-ui.button size="sm" variant="ghost">Перечитать</x-ui.button></form>@endif
                </div>
                @if ($car->locked_fields)<div class="mt-2 text-sm text-ink-muted">Правлено руками: {{ implode(', ', $car->locked_fields) }}</div>@endif
            </x-ui.card>
            @if ($car->offers->isNotEmpty())
            <x-ui.card title="Цены покупателей">
                <div class="flex flex-col divide-y divide-line/40">
                    @foreach ($car->offers as $offer)
                        <div class="flex items-center gap-3 py-2.5">
                            <div class="flex-1">
                                <div class="flex items-baseline gap-2"><span class="font-semibold tabular-nums">{{ number_format($offer->amount, 0, '', ' ') }} ₽</span><span class="text-sm {{ $offer->state === OfferState::Chosen ? 'text-accent-text' : 'text-ink-muted' }}">{{ $offer->state->label() }}</span>@if ($car->price_listing)<span class="text-sm text-ink-dim tabular-nums">{{ $offer->amount >= $car->price_listing ? '+' : '−' }}{{ number_format(abs($offer->amount - $car->price_listing), 0, '', ' ') }}</span>@endif</div>
                                <div class="text-sm text-ink-muted">{{ $offer->user->name }} · <a href="tel:+{{ $offer->user->phone }}" class="text-accent-text">{{ $offer->user->phoneFormatted() }}</a> · {{ $offer->created_at->translatedFormat('j M, H:i') }}</div>
                                @if ($offer->comment)<div class="text-sm">{{ $offer->comment }}</div>@endif
                            </div>
                            @if ($offer->state === OfferState::Active)<form method="post" action="/zakupki/ceny/{{ $offer->id }}/vybrat">@csrf<x-ui.button size="sm">Выбрать</x-ui.button></form>@endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
            @endif
        </div>
    </div>
    <x-ui.action-bar><x-ui.button form="car-form" class="min-w-0 flex-1">Сохранить</x-ui.button></x-ui.action-bar>
</x-ui.shell>
