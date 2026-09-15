@php use App\Cars\{Transmission, Fuel}; use App\Purchases\{Kind, OfferState, ImportState}; $n = $purchase->number; @endphp
<x-ui.shell :title="$car->titleWithYear()" :back="['Закупка', '/zakupki/'.$purchase->number]">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <span class="chip">{{ str_starts_with(mb_strtoupper($car->dl), 'ДЛ') ? $car->dl : 'ДЛ '.$car->dl }}</span>
        <span class="chip">№ {{ $car->ref }}</span>
        @if ($car->site_url)<a href="{{ $car->site_url }}" target="_blank" class="chip">Сайт поставщика ↗</a>@endif
        @if ($car->cloud_url)<a href="{{ $car->cloud_url }}" target="_blank" class="chip">Облако ↗</a>@endif
        @if ($car->visiblePhotos()->isNotEmpty())<x-purchase.share :car="$car" icon/>@endif
        @if ($errors->any())<span class="field-error w-full">{{ $errors->first() }}</span>@endif
    </div>
    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <form method="post" action="/zakupki/{{ $n }}/{{ $car->ref }}" id="car-form" data-controller="vin" class="contents lg:col-start-1 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            @csrf @method('put')
            <x-ui.card title="Машина" class="order-1">
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <x-ui.combobox name="brand_id" label="Марка" url="/spravochnik/marki" create="/spravochnik/marki" :value="$car->brand_id" :text="$car->brand?->name ?? $car->brand_raw" resets="#cb-model_id"/>
                    <x-ui.combobox name="model_id" label="Модель" url="/spravochnik/modeli" create="/spravochnik/modeli" depends="#f-brand_id" :value="$car->model_id" :text="$car->model?->name ?? $car->model_raw"/>
                    <x-ui.field name="year" label="Год" inputmode="numeric" :value="$car->year"/>
                    <x-ui.field name="mileage" label="Пробег, км" inputmode="numeric" :value="$car->mileage"/>
                    <x-ui.vin :value="$car->vin" span="col-span-2 lg:col-span-1"/>
                    <x-ui.field name="kind" label="Категория" :options="Kind::options()" :value="$car->kind->value"/>
                    <x-ui.field name="transmission" label="Коробка" :options="Transmission::options()" placeholder="—" :value="$car->transmission?->value"/>
                    <x-ui.field name="fuel" label="Топливо" :options="Fuel::options()" placeholder="—" :value="$car->fuel?->value"/>
                    <x-ui.field name="engine_volume" label="Объём, см³" inputmode="numeric" :value="$car->engine_volume"/>
                    <x-ui.field name="engine_power" label="Мощность, л. с." inputmode="numeric" :value="$car->engine_power"/>
                    <x-ui.field name="color" label="Цвет" :value="$car->color"/>
                    <x-ui.field name="condition" label="Состояние" :value="$car->condition"/>
                    <x-ui.field name="settlement_id" label="Город" :options="$settlements" placeholder="—" :value="$car->settlement_id"/>
                    <x-ui.field name="address" label="Адрес" :value="$car->address" span="col-span-2 lg:col-span-1"/>
                    <x-ui.field name="description" label="Описание" type="textarea" :value="$car->description" span="col-span-full"/>
                </div>
            </x-ui.card>
            <x-ui.card title="Цены" class="order-1">
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <x-ui.field name="price_revalued" label="С учётом переоценки, ₽" inputmode="numeric" :value="$car->price_revalued"/>
                    <x-ui.field name="price_listing" label="Для размещения, ₽" inputmode="numeric" :value="$car->price_listing"/>
                    <x-ui.field name="price_final" label="Наша цена, ₽" inputmode="numeric" :value="$car->price_final"/>
                    <div class="col-span-full flex flex-wrap items-center gap-x-6 gap-y-2 pt-1">
                        <x-ui.check name="is_published" :checked="$car->is_published">Показывать покупателям</x-ui.check>
                        <x-ui.check name="share_locked" :checked="$car->share_locked">Запретить шеринг</x-ui.check>
                        @if ($car->encumbrance)<span class="tag">{{ $car->encumbrance }}</span>@endif
                        @if ($car->stage)<span class="tag">{{ $car->stage }}</span>@endif
                    </div>
                </div>
            </x-ui.card>
        </form>

        <x-ui.card title="Фотографии" class="order-2 lg:col-span-2" data-controller="photos" data-photos-url-value="/zakupki/mashiny/{{ $car->id }}/media">
            <input type="file" accept="image/*,.heic" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            @if ($car->cloud_url || $car->photos_state->needsAttention())
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <span class="chip {{ $car->photos_state->needsAttention() ? 'bg-urgent-soft text-urgent' : '' }}">{{ $car->photos_state->label() }}{{ $car->photos_error ? ': '.$car->photos_error : '' }}</span>
                    @if ($car->cloud_leftovers)<span class="text-sm text-ink-muted">В облаке осталось: {{ collect($car->cloud_leftovers)->pluck('name')->implode(', ') }}</span>@endif
                </div>
            @endif
            <div hidden data-photos-target="progress" class="mb-3"><div class="mb-1 text-sm text-ink-muted" data-label></div><div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div></div>
            @include('admin.purchases.gallery')
        </x-ui.card>

        <div class="contents lg:col-start-2 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            <x-ui.card title="Характеристики с сайта" class="order-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="chip {{ $car->specs_state->needsAttention() ? 'bg-urgent-soft text-urgent' : '' }}">{{ $car->specs_state->label() }}{{ $car->specs_error ? ': '.$car->specs_error : '' }}</span>
                </div>
                @if ($car->locked_fields)<div class="mt-2 text-sm text-ink-muted">Правлено руками: {{ implode(', ', $car->locked_fields) }}</div>@endif
            </x-ui.card>
            @php $live = $car->activeOfferList(); $chosen = $live->firstWhere('state', OfferState::Chosen); @endphp
            @if ($live->isNotEmpty())
            <x-ui.card title="Предложения менеджеров" class="order-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach ($live as $offer)<x-purchase.offer-chip :offer="$offer" :car="$car"/>@endforeach
                </div>
                @if ($chosen)
                    <div class="mt-3 flex flex-wrap items-center gap-1.5"><x-ui.person :user="$chosen->user" full current/><a href="tel:+{{ $chosen->user->phone }}" class="tag nums">{{ $chosen->user->phoneFormatted() }}</a></div>
                @endif
            </x-ui.card>
            @endif
        </div>
    </div>
    <x-ui.action-bar><x-ui.button form="car-form" class="min-w-0 flex-1">Сохранить</x-ui.button></x-ui.action-bar>
</x-ui.shell>
