{{-- Город продажи (14.11.2026, начальник: 624569/2026 везут из Новосибирска, продают в Москве): галка «Продаём там же —
     город машины», снята — город продажи справочником; первым в списке — куда везёт открытый вывоз (`SaleCity::target`),
     сам он не выбирается: везут и на хранение. Город машины (`settlement_id`) — вывоз и поставщик, не трогается. Сервер —
     `SaleCity` (галка, пустой город или город факта — совпадает). Без города машины куска нет. В одной форме — один раз
     (`SaleCity::claim`: окно — у «Цен», редактор — у «Города факт»): `form` — имя формы, в которой поля (своя форма шторки —
     без него). id — разный у каждого куска на странице: по нему же введённое после ошибки возвращается только в свою форму.
     plate — на плашке (поле белым, как цены). ask — города машины нет: поле «Город» прямо здесь, с той же галкой (18.11.2026,
     владелец: «если регион не указан — поле для удобного указания сразу тут»; у «Цен» окна), в форме оно одно
     (`SaleCity::claimFact`); города из источников — первыми в списке, как у «Города факт» в «Осмотре». --}}
@props(['offer', 'id' => null, 'form' => null, 'plate' => false, 'ask' => false])
@php
    $key = $id ?? 'sale-city-'.$offer->id;
    $fact = $offer->settlement;
    $asks = ! $fact && $ask && \App\Offers\SaleCity::claimFact($offer, $form ?? $key);
    $show = ($fact || $asks) && \App\Offers\SaleCity::claim($offer, $form ?? $key);
    if ($asks) {
        $known = \App\Cars\Sources\Variants::can($offer) ? collect(\App\Cars\Sources\Values::known($offer->car_id)['city'] ?? [])
            ->map(fn ($i) => ['id' => $i['write']['settlement_id'], 'label' => $i['label'], 'hint' => $i['from']])->values()->all() : [];
    }
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
        @if ($asks)
            <x-ui.combobox name="settlement_id" :id="$key.'-fact'" label="Город" url="/reference/settlements" create="/reference/settlements" :pinned="$known ?: null" :form="$form" placeholder="Начните вводить город" :class="$plate ? '!bg-surface' : null"/>
        @endif
        <input type="hidden" name="_sale_city" value="{{ $key }}" @if ($form) form="{{ $form }}" @endif>
        <input type="hidden" name="sale_city_same" value="0" @if ($form) form="{{ $form }}" @endif>
        {{-- Той же маленькой галкой и размером, что «С НДС» (`.flags`, 16.11.2026: две галки разного размера в одном блоке). --}}
        <span class="flags pt-0"><x-ui.check name="sale_city_same" :checked="$same" :old="false" data-action="reveal#check" data-reveal-key-param="other" :form="$form">Продаём там же{{ $fact ? ', '.$fact->title() : '' }}</x-ui.check></span>
        <div data-reveal-target="pane" data-reveal-key="other" @if ($same) hidden @endif>
            <x-ui.combobox name="sale_settlement_id" :id="$key" label="Город продажи" url="/reference/settlements" create="/reference/settlements" :value="$sale?->id" :text="$sale?->title()" :pinned="$pinned" :form="$form" :old="false" placeholder="Начните вводить город" :class="$plate ? '!bg-surface' : null"/>
        </div>
    </div>
@endif
