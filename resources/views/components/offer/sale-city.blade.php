{{-- Город продажи (14.11.2026, начальник: 624569/2026 везут из Новосибирска, продают в Москве): галка «Продаём там же —
     город машины», снята — город продажи справочником; первым в списке — куда везёт открытый вывоз (`SaleCity::target`),
     сам он не выбирается: везут и на хранение. Город машины (`settlement_id`) — вывоз и поставщик, не трогается. Сервер —
     `SaleCity` (галка, пустой город или город факта — совпадает). Без города машины куска нет. В одной форме — один раз
     (`SaleCity::claim`: окно — у «Цен», редактор — у «Города факт»): `form` — имя формы, в которой поля (своя форма шторки —
     без него). id — разный у каждого куска на странице: по нему же введённое после ошибки возвращается только в свою форму.
     plate — на плашке (поле белым, как цены). --}}
@props(['offer', 'id' => null, 'form' => null, 'plate' => false])
@php
    $key = $id ?? 'sale-city-'.$offer->id;
    $fact = $offer->settlement;
    $show = $fact && \App\Offers\SaleCity::claim($offer, $form ?? $key);
    if ($show) {
        $toCity = \App\Offers\SaleCity::target($offer);
        $mine = old('_sale_city') === $key;
        $oldSale = $mine ? old('sale_settlement_id') : null;
        $sale = $mine ? ($oldSale ? \App\Cars\Settlement::find($oldSale) : null) : ($offer->sale_settlement_id ? $offer->saleSettlement : null);
        $same = $mine ? filter_var(old('sale_city_same', true), FILTER_VALIDATE_BOOL) : ! $sale;
        $pinned = $toCity && $toCity->id !== $sale?->id ? [['id' => $toCity->id, 'label' => $toCity->title(), 'hint' => 'куда везут']] : null;
    }
@endphp
@if ($show)
    <div {{ $attributes->class(['sale-city flex flex-col gap-2']) }} data-controller="reveal">
        <input type="hidden" name="_sale_city" value="{{ $key }}" @if ($form) form="{{ $form }}" @endif>
        <input type="hidden" name="sale_city_same" value="0" @if ($form) form="{{ $form }}" @endif>
        <x-ui.check name="sale_city_same" :checked="$same" :old="false" data-action="reveal#check" data-reveal-key-param="other" :form="$form">Продаём там же, {{ $fact->title() }}</x-ui.check>
        <div data-reveal-target="pane" data-reveal-key="other" @if ($same) hidden @endif>
            <x-ui.combobox name="sale_settlement_id" :id="$key" label="Город продажи" url="/reference/settlements" create="/reference/settlements" :value="$sale?->id" :text="$sale?->title()" :pinned="$pinned" :form="$form" :old="false" placeholder="Начните вводить город" :class="$plate ? '!bg-surface' : null"/>
        </div>
    </div>
@endif
