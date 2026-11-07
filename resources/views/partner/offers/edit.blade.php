{{-- Редактор черновика у поставщика (partner.xcar.ru, 07.11.2026). Сверху «Цены»: оценочная (поле пустое — вписывает он: у машины
     бывает заявленная стоимость парковки, её не показываем и не стираем), диапазоны наценки этого
     предложения и закупочная итогом (её считает сервер, `OfferRequest::payload`); ниже ТС, документы ТС, характеристики,
     осмотр, фото и документы — те же поля, что у модератора, но без вендора (он зашит), без источников, вариантов, подсказок,
     разбора текста и «Заполнить по VIN»: поставщик вписывает сам. Номер убытка — чипом, не полем. Внизу «Сохранить» и
     «Сохранить и дальше» — к следующему без цены. --}}
@php
    use App\Cars\CarFields;
    use App\Offers\Offer;
    use App\Support\{Liters, Money};
    $blocks = \App\Http\Partner\OfferRequest::BLOCKS;
    $fields = \App\Http\Partner\OfferRequest::carFields();
    $raw = function (string $c) use ($offer) {
        $v = old(CarFields::name(Offer::class, $c), $offer->{CarFields::name(Offer::class, $c)});

        return $v instanceof \BackedEnum ? $v->value : $v;
    };
    $binding = \App\Offers\Binding::applies($offer);
@endphp
<x-ui.shell :title="$offer->brand_id ? $offer->title() : 'Новое предложение'" back="/offers">
    <form method="post" action="/offers/{{ $offer->id }}" id="partner-offer" class="flex flex-col gap-4" data-controller="value-floor"
        data-value-floor-rules-value="@json($rules)" data-action="floor-rules:change->value-floor#rules">
        @csrf @method('put')
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.copy-code :value="$offer->claim_ref" class="chip nums"/>
            @if ($offer->vendor)<span class="text-sm text-ink-muted">{{ $offer->vendor->name }}</span>@endif
        </div>

        <div class="@container">
        <div class="grid grid-cols-1 items-start gap-4 @4xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="flex min-w-0 flex-col gap-4">
                <x-ui.card title="Цены" id="prices">
                    <div class="flex flex-col gap-3">
                        <div class="field @error('value') field-invalid @enderror">
                            <label for="partner-value" class="field-label">Оценочная, ₽</label>
                            <input type="text" id="partner-value" name="value" inputmode="decimal" autocomplete="off" enterkeyhint="done" class="field-input nums"
                                data-controller="digits" data-action="input->digits#format input->value-floor#sync" data-value-floor-target="value"
                                value="{{ old('value') }}">
                            @error('value')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <span class="field-label">Наценка к оценочной</span>
                            <x-offer.floor-rules :rules="$rules" :default="$default" id="partner-rules"/>
                            @error('floor_rules')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="flex items-baseline justify-between gap-3 rounded-(--radius-m) bg-surface-2 px-3 py-2.5">
                            <span class="text-ink-muted">Закупочная</span>
                            <span class="nums font-semibold" data-value-floor-target="result"></span>
                        </div>
                        @if ($binding)
                            <x-ui.field name="binding_until" label="Срок обязывающего предложения" type="datetime-local" default-time="23:59"
                                :value="old('binding_until', $offer->binding_until?->format('Y-m-d\TH:i'))"/>
                        @endif
                    </div>
                </x-ui.card>

                @foreach ($blocks as $block)
                    @php [$title, $columns] = CarFields::BLOCKS[$block]; $columns = array_values(array_filter($columns, fn ($c) => in_array(CarFields::name(Offer::class, $c), $fields, true))); @endphp
                    <x-ui.card :title="$title">
                        <div class="grid grid-cols-2 gap-3 @4xl:grid-cols-3">
                            @foreach ($columns as $c)
                                @php $name = CarFields::name(Offer::class, $c); @endphp
                                @switch (CarFields::kind($c))
                                    @case ('brand')
                                        <x-cars.brand-model :brand-id="$raw('brand_id')" :brand-text="$offer->car?->brand?->name" :model-id="$raw('model_id')" :model-text="$offer->car?->model?->name" required/>
                                        @break
                                    @case ('model')
                                        @break
                                    @case ('enum')
                                        <x-ui.field :name="$name" :label="CarFields::label($c)" :options="CarFields::enum($c)::options()" placeholder="—" :value="$raw($c)"/>
                                        @break
                                    @case ('liters')
                                        <x-ui.field :name="$name" :label="CarFields::label($c)" placeholder="1,6" :value="Liters::format($raw($c))"/>
                                        @break
                                    @case ('vin')
                                        <x-ui.field :name="$name" label="VIN" :value="$raw($c)" span="col-span-2 @4xl:col-span-1"/>
                                        @break
                                    @default
                                        <x-ui.field :name="$name" :label="CarFields::label($c)" :value="$raw($c)" :digits="CarFields::kind($c) === 'digits'"/>
                                @endswitch
                            @endforeach
                        </div>
                    </x-ui.card>
                @endforeach

                <x-ui.card title="Осмотр">
                    <div class="grid grid-cols-2 gap-3 @4xl:grid-cols-3">
                        <x-ui.combobox name="settlement_id" label="Город" url="/reference/settlements" :value="old('settlement_id', $offer->settlement_id)" :text="$offer->settlement?->title()"/>
                        <x-ui.field name="inspection_address" label="Адрес осмотра" :value="old('inspection_address', $offer->inspection_address)" span="col-span-2"/>
                        <x-ui.field name="description" label="Описание" type="textarea" :value="old('description', $offer->description)" span="col-span-full"/>
                    </div>
                </x-ui.card>
            </div>

            <div class="flex min-w-0 flex-col gap-4">
                <x-ui.card title="Фотографии">@include('partner.offers.photos', ['offer' => $offer])</x-ui.card>
                <x-ui.card title="Документы">@include('partner.offers.papers', ['offer' => $offer])</x-ui.card>
            </div>
        </div>
        </div>
    </form>

    {{-- Свой черновик без цены — удалить (опечатка в номере): редко, поэтому внизу страницы, а не в плашке. --}}
    @if ($offer->isDeletableBy(auth()->user()))
        <form method="post" action="/offers/{{ $offer->id }}" class="mt-6" data-turbo-confirm="Удалить черновик? Фото и документы удалятся вместе с ним">@csrf @method('delete')<x-ui.button variant="ghost" class="text-danger"><x-ui.icon name="trash" class="size-5"/> Удалить черновик</x-ui.button></form>
    @endif

    <x-ui.action-bar>
        <x-ui.button form="partner-offer" name="then" value="close" variant="secondary" class="min-w-0 flex-1">Сохранить</x-ui.button>
        <x-ui.button form="partner-offer" name="then" value="next" class="min-w-0 flex-1">Сохранить и дальше</x-ui.button>
    </x-ui.action-bar>
</x-ui.shell>
