{{-- Поля «Деньги» — одни на редактор и карточка строки, узкой колонкой в две: закупочная во всю ширину с «С НДС», заявленная и цена продажи, минимальная и доля; «С НДС» у закупочной (и у модератора: цены от страховой бывают и так, и так), метки — в карточке строки (в редакторе они в
     шапке); срок приёма, кому показывать и галки показа — «Показ» (fields/show) под описанием. В редакторе блок стоит справа над
     «Историей», вне формы — поля ходят в неё через `form` ($form). Серые подсказки заявленной и минимальной считаются на
     ходу (min-bid). Модератору — закупочная и НДС: цены продажи, галки, метки и приём ставит админ (OfferRequest::MODERATOR). --}}
@php
    $form ??= null;
    $byValue = once(fn () => \App\Vendors\Vendor::where('rate_by_value', true)->pluck('id')->all());
@endphp
<div class="grid grid-cols-2 gap-3" data-controller="min-bid value-floor">
    @if (auth()->user()->canManageCrm())
    {{-- Оценочная — только у вендора, чья закупочная от неё (`rate_by_value`, Альфа; владелец 05.10.2026: «у Совкомбанка
         поля быть не должно»), даже если с парковки она пришла; та же, что у ТС парковки (`Sale::MAP`). --}}
    <div class="col-span-2" data-controller="by-vendor" data-by-vendor-ids-value="{{ json_encode($byValue) }}" @unless (in_array($offer->vendor_id, $byValue, true)) hidden @endunless>
        <x-ui.field name="value" data-controller="digits" data-action="input->digits#format input->value-floor#sync" data-value-floor-target="value" label="Оценочная, ₽" :value="$offer->value" :form="$form"/>
    </div>
    <x-ui.field name="floor_price" data-controller="digits" data-value-floor-target="floor" data-action="input->digits#format" label="Закупочная, ₽" :value="$offer->floor_price" :form="$form" span="col-span-2">
        <x-slot:after-label><span class="flags ml-auto pt-0"><x-ui.check name="prices_include_vat" :checked="$offer->prices_include_vat" :form="$form">С НДС</x-ui.check></span></x-slot:after-label>
    </x-ui.field>
    <x-ui.field name="publish_price" data-controller="digits" data-action="input->digits#format" label="Заявленная, ₽" :value="$offer->publish_price" :form="$form" :placeholder="$offer->floor_price ? \App\Support\Money::nums(\App\Offers\Offer::declaredFrom($offer->floor_price)) : null"/>
    {{-- В карточке у черновика цена продажи стоит в «Оценке» рядом с «В продажу» — второй раз не нужна. --}}
    @unless ($askingElsewhere ?? false)<x-ui.field name="asking_price" data-controller="digits" data-action="input->digits#format" label="Цена продажи, ₽" :value="$offer->asking_price" :form="$form"/>@endunless
    <x-ui.field name="min_bid_price" data-controller="digits" data-action="input->digits#format" label="Минимальная, ₽" :value="$offer->min_bid_price" :form="$form" :placeholder="$offer->minBid() ? \App\Support\Money::nums($offer->minBid()) : null"/>
    <x-ui.field name="min_bid_share" label="Доля до продажной" :value="$offer->min_bid_share" :form="$form" placeholder="0,6"/>
    {{-- Метки: в редакторе — в шапке пилюлями, здесь только в карточке строки. --}}
    @if ($withTags ?? true)<div class="col-span-2">@include('admin.offers.fields.tags')</div>@endif
    @else
    {{-- Оценочная — у вендора, чья закупочная от неё (`rate_by_value`, Альфа); та же, что у ТС парковки (`Sale::MAP`). --}}
    <div class="col-span-2" data-controller="by-vendor" data-by-vendor-ids-value="{{ json_encode($byValue) }}" @unless (in_array($offer->vendor_id, $byValue, true) || $offer->value) hidden @endunless>
        <x-ui.field name="value" data-controller="digits" data-action="input->digits#format input->value-floor#sync" data-value-floor-target="value" label="Оценочная, ₽" :value="$offer->value" :form="$form"/>
    </div>
    <x-ui.field name="floor_price" data-controller="digits" data-value-floor-target="floor" data-action="input->digits#format" label="Закупочная, ₽" :value="$offer->floor_price" :form="$form" span="col-span-2">
        <x-slot:after-label><span class="flags ml-auto pt-0"><x-ui.check name="prices_include_vat" :checked="$offer->prices_include_vat" :form="$form">С НДС</x-ui.check></span></x-slot:after-label>
    </x-ui.field>
    @endif
</div>
