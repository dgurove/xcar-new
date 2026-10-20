{{-- «Продана на аукционе» (20.10.2026, Совкомбанк): покупатель аукциона — физлицо или организация, цена, когда; «Счёт
     выставлен вне системы» — без счёта (покупатель и цена тогда по желанию). Сделка без менеджера закрывается сразу
     (`SellAtAuction`). Открывает событие auction-sale-{номер}:open — кнопка плашки «На аукционе», пункт «Действий» и
     «Аукцион» в «Кто вывозит»; `action` — CRM `/offers/{n}/auction/sold` или логистика `/transports/{id}/auction`. --}}
@props(['offer', 'action' => null])
@php
    $n = $offer->number;
    $action ??= "/offers/{$n}/auction/sold";
    $kind = old('buyer_kind', 'person');
@endphp
<div data-controller="sheet" data-action="auction-sale-{{ $n }}:open@window->sheet#open" class="contents">
    <x-ui.sheet id="auction-sale-{{ $n }}" title="Продана на аукционе">
        <form method="post" action="{{ $action }}" class="flex flex-col gap-4" data-controller="reveal"
              data-turbo-confirm="Продана на аукционе?" data-turbo-confirm-text="Предложение закроется, вывоз отметится «Забрал аукцион»" data-turbo-confirm-label="Продана">
            @csrf
            <x-ui.segment name="buyer_kind" :options="['person' => 'Физлицо', 'company' => 'Организация']" :value="$kind" pick/>
            <div class="contents" data-reveal-target="pane" data-reveal-key="person" @if ($kind !== 'person') hidden @endif>
                <div class="fields">
                    <x-ui.field name="buyer_name" :id="'auction-person-'.$n" label="ФИО покупателя" :value="$kind === 'person' ? old('buyer_name') : null"/>
                    <x-ui.field name="buyer_phone" :id="'auction-phone-'.$n" label="Телефон" type="tel" :value="old('buyer_phone')"/>
                </div>
            </div>
            <div class="contents" data-reveal-target="pane" data-reveal-key="company" @if ($kind !== 'company') hidden @endif>
                <div class="fields">
                    <x-ui.field name="buyer_name" :id="'auction-company-'.$n" label="Организация" :value="$kind === 'company' ? old('buyer_name') : null"/>
                    <x-ui.field name="buyer_inn" :id="'auction-inn-'.$n" label="ИНН" :value="old('buyer_inn')"/>
                </div>
            </div>
            <div class="fields">
                <x-ui.field name="price" :id="'auction-price-'.$n" label="Цена, ₽" :value="old('price')"/>
                <x-ui.field name="sold_on" :id="'auction-day-'.$n" label="Когда" type="date" :value="old('sold_on', now()->toDateString())" :max="now()->toDateString()" required/>
            </div>
            <input type="hidden" name="outside" value="0">
            <x-ui.check name="outside" :checked="(bool) old('outside')">Счёт выставлен вне системы</x-ui.check>
            @error('offer')<p class="field-error">{{ $message }}</p>@enderror
            <x-ui.sheet-foot>Продана на аукционе</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
