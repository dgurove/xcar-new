{{-- Цены у опубликованной машины числами (06.10.2026): крупно цена продажи, ниже строками закупочная, заявленная,
     минимальная (своя или посчитанная), оценочная — что есть. Гаражной — только закупочная: цены показа ей ни к чему.
     Править — «Развернуть» в заголовке карточки. --}}
@php
    use App\Support\Money;
    $garageOnly ??= false;
    $rows = array_filter($garageOnly ? [] : [
        'Заявленная' => $offer->declaredPrice(),
        'Минимальная' => $offer->minBid(),
        'Оценочная' => $offer->value,
    ]);
@endphp
<div class="flex flex-col gap-3">
    @if (! $garageOnly && $offer->asking_price)
        <div>
            <div class="text-sm text-ink-muted">Цена продажи</div>
            <div class="nums text-[28px] font-bold leading-tight">{{ Money::rub($offer->asking_price) }}</div>
        </div>
    @endif
    <div class="list">
        @if ($offer->floor_price)
            <div class="row justify-between"><span>Закупочная@if ($offer->prices_include_vat) <span class="text-ink-muted">с НДС</span>@endif</span><span class="nums">{{ Money::rub($offer->floor_price) }}</span></div>
        @endif
        @foreach ($rows as $label => $value)
            <div class="row justify-between"><span>{{ $label }}</span><span class="nums">{{ Money::rub($value) }}</span></div>
        @endforeach
    </div>
</div>
