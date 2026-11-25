{{-- Шкала «что куда от цены продажи» (25.11.2026, владелец выбрал из макетов вариант 1: «нужны шкалы, что куда от цены продажи»): тонкая
     полоса под ценой в «Продаже» — закупочная (или сколько поставщику) серым постоянной долей, дальше по суммам: сверх неё в счёте,
     подбор ссылкой, менеджеру, наличные. Цвета — токены `--color-share-*`, те же у полос шторки денег (`x-deal.price-money`). --}}
@props(['parts' => []])
@php $parts = array_filter($parts, fn ($sum) => $sum > 0); @endphp
<div {{ $attributes->class('money-scale') }} aria-hidden="true">
    <span @class(['money-scale-seg money-scale-seg--cost', 'is-whole' => ! $parts])></span>
    @foreach ($parts as $key => $sum)
        <span class="money-scale-seg money-scale-seg--{{ $key }}" style="flex-grow: {{ $sum }}"></span>
    @endforeach
</div>
