{{-- Поля «Деньги» — одни на редактор и окошко строки, узкой колонкой в две: закупочная и заявленная, цена продажи во всю
     ширину (она главная), минимальная и доля, срок приёма, галки в две колонки (метки — в окошке строки; в редакторе они в шапке). В редакторе блок стоит справа над
     «Историей», вне формы — поля ходят в неё через `form` ($form). Серые подсказки заявленной и минимальной считаются на
     ходу (min-bid). Модератору — только закупочная: цены продажи, галки, метки и приём ставит админ (OfferRequest::MODERATOR). --}}
@php $form ??= null; @endphp
<div class="grid grid-cols-2 gap-3" data-controller="min-bid">
    @if (auth()->user()->canManageCrm())
    <x-ui.field name="floor_price" data-controller="digits" data-action="input->digits#format" label="Закупочная, ₽" :value="$offer->floor_price" :form="$form"/>
    <x-ui.field name="publish_price" data-controller="digits" data-action="input->digits#format" label="Заявленная, ₽" :value="$offer->publish_price" :form="$form" :placeholder="$offer->floor_price ? \App\Support\Money::nums(\App\Offers\Offer::declaredFrom($offer->floor_price)) : null"/>
    {{-- В окошке у черновика цена продажи стоит в «Оценке» рядом с «В продажу» — второй раз не нужна. --}}
    @unless ($askingElsewhere ?? false)<x-ui.field name="asking_price" data-controller="digits" data-action="input->digits#format" label="Цена продажи, ₽" :value="$offer->asking_price" :form="$form" span="col-span-2"/>@endunless
    <x-ui.field name="min_bid_price" data-controller="digits" data-action="input->digits#format" label="Минимальная, ₽" :value="$offer->min_bid_price" :form="$form" :placeholder="$offer->minBid() ? \App\Support\Money::nums($offer->minBid()) : null"/>
    <x-ui.field name="min_bid_share" label="Доля до продажной" :value="$offer->min_bid_share" :form="$form" placeholder="0,6"/>
    <x-ui.field name="bids_close_at" label="Приём подтверждений до" type="datetime-local" data-controller="evening" data-action="change->evening#fix" :value="$offer->bids_close_at?->format('Y-m-d\TH:i')" :form="$form" span="col-span-2"/>
    <div class="col-span-2 grid grid-cols-2 gap-x-4 gap-y-3 pt-1">
        <x-ui.check name="recommended" :checked="$offer->recommended" :form="$form">Рекомендуем</x-ui.check>
        <x-ui.check name="prices_include_vat" :checked="$offer->prices_include_vat" :form="$form">С НДС</x-ui.check>
        <x-ui.check name="chat_enabled" :checked="$offer->chat_enabled" :form="$form">Чат</x-ui.check>
        <x-ui.check name="share_locked" :checked="$offer->share_locked" :form="$form">Без шеринга</x-ui.check>
    </div>
    {{-- Метки: в редакторе — в шапке пилюлями, здесь только в окошке строки. --}}
    @if ($withTags ?? true)<div class="col-span-2">@include('admin.offers.fields.tags')</div>@endif
    @else
    <x-ui.field name="floor_price" data-controller="digits" data-action="input->digits#format" label="Закупочная, ₽" :value="$offer->floor_price" :form="$form" span="col-span-2"/>
    @endif
</div>
