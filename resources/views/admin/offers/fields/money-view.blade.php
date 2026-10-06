{{-- «Цены» текстом (06.10.2026, «Изменить / Готово»): плотными строками «подпись слева, сумма справа», как настройки
     iOS; правый край сумм — по «Изменить». Порядок — как у полей: оценочная, закупочная, заявленная, цена продажи,
     минимальная (заявленная и минимальная — как посчитаны); пустые не показываются. Гаражной — только закупочная. --}}
@php
    use App\Support\Money;
    $garageOnly ??= false;
    $rub = fn ($v) => $v ? Money::rub($v) : null;
    $rows = array_filter($garageOnly ? ['Закупочная' => $rub($offer->floor_price)] : [
        'Оценочная' => $rub($offer->value),
        'Закупочная' => $rub($offer->floor_price),
        'Заявленная' => $rub($offer->declaredPrice()),
        'Цена продажи' => $rub($offer->asking_price),
        'Минимальная' => $rub($offer->minBid()),
    ]);
@endphp
<dl class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-2 leading-snug">
    @foreach ($rows as $label => $value)
        <dt class="text-ink-dim">{{ $label }}@if ($label === 'Закупочная' && $offer->prices_include_vat) <span class="text-sm">с НДС</span>@endif</dt>
        <dd @class(['nums text-right', 'font-semibold' => $label === 'Цена продажи'])>{{ $value }}</dd>
    @endforeach
</dl>
