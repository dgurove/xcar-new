{{-- Город продажи (14.11.2026, начальник: 624569/2026 везут из Новосибирска, продают в Москве): галка «Продаём там же —
     город машины», снята — город продажи справочником. Город машины (`settlement_id`) — вывоз и поставщик, не трогается.
     Сервер — `Offer::saleCityFrom` (снятая галка без города или город машины — совпадает). Без города машины куска нет. В одной форме — один раз: окно —
     у «Цен» (`fields/money` с `saleCity`), редактор — у «Города факт» (`fields/sale`). id — когда кусок стоит на странице дважды; form — поля вне своей формы; plate — на плашке (поле белым, как цены). --}}
@props(['offer', 'id' => null, 'form' => null, 'suggest' => false, 'plate' => false])
@php
    $key = $id ?? 'sale-city-'.$offer->id;
    $fact = $offer->settlement;
    $sale = $offer->sale_settlement_id ? $offer->saleSettlement : null;
    // Открытый вывоз в другой город — он первым в списке, а в шторках продажи (`suggest`) уже выбран, если город продажи не вписан.
    $to = \App\Logistics\Transport::openFor($offer)?->toSettlementId();
    $toCity = $to && $to !== $offer->settlement_id ? \App\Cars\Settlement::find($to) : null;
    $sale ??= $suggest ? $toCity : null;
    $same = ! $sale && filter_var(old('sale_city_same', true), FILTER_VALIDATE_BOOL);
    $pinned = $toCity ? [['id' => $toCity->id, 'label' => $toCity->title(), 'hint' => 'куда везут']] : null;
@endphp
@if ($fact)
    <div {{ $attributes->class(["sale-city flex flex-col gap-2"]) }} data-controller="reveal">
        <input type="hidden" name="sale_city_same" value="0" @if ($form) form="{{ $form }}" @endif>
        <x-ui.check name="sale_city_same" :checked="$same" data-action="reveal#check" data-reveal-key-param="other" :form="$form">Продаём там же, {{ $fact->title() }}</x-ui.check>
        <div data-reveal-target="pane" data-reveal-key="other" @if ($same) hidden @endif>
            <x-ui.combobox name="sale_settlement_id" :id="$key" label="Город продажи" url="/reference/settlements" create="/reference/settlements" :value="old('sale_settlement_id', $sale?->id)" :text="$sale?->title()" :pinned="$pinned" :form="$form" placeholder="Начните вводить город" :class="$plate ? '!bg-surface' : null"/>
        </div>
    </div>
@endif
