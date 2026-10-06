{{-- «Цены» текстом (06.10.2026, «Изменить / Готово»): две колонки, как у полей (`fields.money`), но плотнее — главная
     пара «закупочная, продажа» строкой сверху, под ней заявленная и минимальная; пустые не показываются, заявленная и минимальная — как посчитаны. Гаражной —
     только закупочная. --}}
@php
    use App\Support\Money;
    $garageOnly ??= false;
    $rub = fn ($v) => $v ? Money::rub($v) : null;
    $cells = array_filter($garageOnly ? ['Закупочная' => $rub($offer->floor_price)] : [
        'Закупочная' => $rub($offer->floor_price),
        'Цена продажи' => $rub($offer->asking_price),
        'Заявленная' => $rub($offer->declaredPrice()),
        'Минимальная' => $rub($offer->minBid()),
        'Оценочная' => $rub($offer->value),
    ]);
@endphp
<div class="grid grid-cols-2 gap-3">
    @foreach ($cells as $label => $value)
        <div class="min-w-0">
            <div class="field-label">{{ $label }}@if ($label === 'Закупочная' && $offer->prices_include_vat) <span class="text-ink-muted">с НДС</span>@endif</div>
            <div @class(['nums mt-1', 'text-lg font-semibold' => $label === 'Цена продажи'])>{{ $value }}</div>
        </div>
    @endforeach
</div>
