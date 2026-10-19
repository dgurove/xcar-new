{{-- Поля «Деньги» — одни на редактор и карточка строки, узкой колонкой в две: закупочная во всю ширину с «С НДС», заявленная и цена продажи, минимальная и доля; «С НДС» у закупочной (и у модератора: цены от страховой бывают и так, и так), метки — в карточке строки (в редакторе они в
     шапке); срок приёма, кому показывать и галки показа — модуль «Показ» (`x-offer.showing`) под «Ценами». В редакторе блок стоит справа над
     «Историей», вне формы — поля ходят в неё через `form` ($form). Серые подсказки заявленной и минимальной считаются на
     ходу (min-bid). Срок обязывающего предложения — под закупочной, у админа и модератора. floorElsewhere — закупочная с оценочной сверху карточки, здесь их нет. Модератору — закупочная и НДС: цены продажи, галки, метки и приём ставит админ (OfferRequest::MODERATOR). --}}
@php
    $form ??= null;
    // Закупочная стоит сверху карточки (вкладка «Без закупочной цены») — второй раз не нужна.
    $floorElsewhere ??= false;
    $byValue = once(fn () => \App\Vendors\Vendor::where('rate_by_value', true)->pluck('id')->all());
@endphp
<div class="grid grid-cols-2 gap-3" data-controller="min-bid value-floor">
    @if (auth()->user()->canManageCrm())
    {{-- Оценочная — только у вендора, чья закупочная от неё (`rate_by_value`, Альфа; владелец 05.10.2026: «у Совкомбанка
         поля быть не должно»), даже если с парковки она пришла; та же, что у ТС парковки (`Cars\Car`: оценочная у машины одна). --}}
    @unless ($floorElsewhere)
    <div class="col-span-2" data-controller="by-vendor" data-by-vendor-ids-value="{{ json_encode($byValue) }}" @unless (in_array($offer->vendor_id, $byValue, true)) hidden @endunless>
        <x-ui.field digits name="value" data-action="input->value-floor#sync" data-value-floor-target="value" label="Оценочная, ₽" :value="$offer->value" :form="$form"/>
    </div>
    <x-ui.field name="floor_price" data-controller="digits" data-value-floor-target="floor" data-action="input->digits#format" label="Закупочная, ₽" :value="$offer->floor_price" :form="$form" span="col-span-2">
        <x-slot:after-label><span class="flags ml-auto pt-0"><x-ui.check name="prices_include_vat" :checked="$offer->prices_include_vat" :form="$form">С НДС</x-ui.check></span></x-slot:after-label>
    </x-ui.field>
    @endunless
    {{-- Срок обязывающего предложения (19.10.2026): до когда поставщик держит эту закупочную; приём подтверждений — не позже. --}}
    @unless ($garageOnly ?? false)
    @php $bindingDays = $offer->vendor_id ? (int) \App\Vendors\Vendor::whereKey($offer->vendor_id)->value('binding_days') : 0; @endphp
    <x-ui.field name="binding_until" label="Срок обязывающего предложения" type="datetime-local" default-time="23:59" :value="$offer->binding_until?->format('Y-m-d\TH:i')" :placeholder="$bindingDays ? 'по вендору '.$bindingDays.' дн' : null" :form="$form" span="col-span-2"/>
    @endunless
    {{-- Гаражной машине цены показа ни к чему: продаёт менеджер сам, своему покупателю (06.10.2026). --}}
    @unless ($garageOnly ?? false)
    <x-ui.field name="publish_price" label="Заявленная, ₽" :value="$offer->publish_price" :form="$form" :placeholder="$offer->bidFloor() ? \App\Support\Money::nums(\App\Offers\Offer::declaredFrom($offer->bidFloor())) : null"/>
    {{-- В карточке у черновика цена продажи стоит в «Оценке» рядом с «В продажу» — второй раз не нужна. --}}
    @unless ($askingElsewhere ?? false)<x-ui.field name="asking_price" label="Цена продажи, ₽" :value="$offer->asking_price" :form="$form"/>@endunless
    <x-ui.field name="min_bid_price" label="Минимальная, ₽" :value="$offer->min_bid_price" :form="$form" :placeholder="$offer->minBid() ? \App\Support\Money::nums($offer->minBid()) : null"/>
    <x-ui.field name="min_bid_share" label="Доля до продажной" :value="$offer->min_bid_share" :form="$form" placeholder="0,6"/>
    {{-- Метки: в редакторе — в шапке пилюлями, здесь только в карточке строки. --}}
    @endunless
    @if ($withTags ?? true)<div class="col-span-2">@include('admin.offers.fields.tags')</div>@endif
    @else
    {{-- Оценочная — у вендора, чья закупочная от неё (`rate_by_value`, Альфа); та же, что у ТС парковки (`Cars\Car`: оценочная у машины одна). --}}
    @unless ($floorElsewhere)
    <div class="col-span-2" data-controller="by-vendor" data-by-vendor-ids-value="{{ json_encode($byValue) }}" @unless (in_array($offer->vendor_id, $byValue, true) || $offer->value) hidden @endunless>
        <x-ui.field digits name="value" data-action="input->value-floor#sync" data-value-floor-target="value" label="Оценочная, ₽" :value="$offer->value" :form="$form"/>
    </div>
    <x-ui.field name="floor_price" data-controller="digits" data-value-floor-target="floor" data-action="input->digits#format" label="Закупочная, ₽" :value="$offer->floor_price" :form="$form" span="col-span-2">
        <x-slot:after-label><span class="flags ml-auto pt-0"><x-ui.check name="prices_include_vat" :checked="$offer->prices_include_vat" :form="$form">С НДС</x-ui.check></span></x-slot:after-label>
    </x-ui.field>
    @endunless
    {{-- Срок обязывающего предложения (19.10.2026): до когда поставщик держит эту закупочную; приём подтверждений — не позже. --}}
    @unless ($garageOnly ?? false)
    @php $bindingDays = $offer->vendor_id ? (int) \App\Vendors\Vendor::whereKey($offer->vendor_id)->value('binding_days') : 0; @endphp
    <x-ui.field name="binding_until" label="Срок обязывающего предложения" type="datetime-local" default-time="23:59" :value="$offer->binding_until?->format('Y-m-d\TH:i')" :placeholder="$bindingDays ? 'по вендору '.$bindingDays.' дн' : null" :form="$form" span="col-span-2"/>
    @endunless
    @endif
</div>
