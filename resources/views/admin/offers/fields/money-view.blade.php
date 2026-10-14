{{-- «Цены» текстом (06.10.2026, «Изменить / Готово»): плотными строками «подпись слева, сумма справа», как настройки
     iOS; правый край сумм — по «Изменить». Порядок — как у полей: оценочная, закупочная, заявленная, цена продажи,
     минимальная (заявленная и минимальная — как посчитаны); пустые не показываются. Гаражной — только закупочная.
     Цену правили (14.10.2026, `Offers\PriceHistory`) — прежняя сумма зачёркнутой, нажатие — шторка со всеми правками:
     когда, кто, откуда; у закупочной там же закупочная в сделке. --}}
@php
    use App\Support\Money;
    $garageOnly ??= false;
    $history = \App\Offers\PriceHistory::of($offer);
    $rows = array_filter($garageOnly ? ['floor_price' => ['Закупочная', $offer->floor_price]] : [
        'value' => ['Оценочная', $offer->value],
        'floor_price' => ['Закупочная', $offer->floor_price],
        'publish_price' => ['Заявленная', $offer->declaredPrice()],
        'asking_price' => ['Цена продажи', $offer->asking_price],
        'min_bid_price' => ['Минимальная', $offer->minBid()],
    ], fn ($row) => (bool) $row[1]);
@endphp
<dl class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-2 leading-snug">
    @foreach ($rows as $field => [$label, $value])
        <dt class="text-ink-dim">{{ $label }}@if ($field === 'floor_price' && $offer->prices_include_vat) <span class="text-sm">с НДС</span>@endif</dt>
        <dd @class(['nums text-right', 'font-semibold' => $field === 'asking_price', 'text-danger' => $field === 'asking_price' && ($offer->marginMark()['tone'] ?? null) === 'loss'])>
            @if ($history->has($field))
                <span class="contents" data-controller="sheet">
                    <button type="button" class="nums text-right" data-action="sheet#open">@if (($was = $history->previous($field)) && $was !== (int) $value)<s class="mr-1.5 text-sm font-normal text-ink-dim">{{ Money::nums($was) }}</s>@endif{{ Money::rub($value) }}</button>
                    <x-ui.sheet :id="'price-'.$field.'-'.$offer->number" :title="$label">
                        <div class="flex flex-col gap-3 text-left text-sm font-normal">
                            @foreach ($history->rows($field) as $row)
                                <div>
                                    <div class="nums leading-snug">{{ \App\Support\Changes::pair('floor_price', $row['was'], $row['now']) }}@if (! empty($row['deal'])) <span class="text-ink-muted">в сделке</span>@endif</div>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-dim">
                                        <span class="nums">{{ $row['at']->translatedFormat($row['at']->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i') }}</span>
                                        @if ($row['user'])<span>{{ $row['user']->shortName() }}</span>@endif
                                        @if ($row['why'])<span>{{ $row['why'] }}</span>@endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.sheet>
                </span>
            @else
                {{ Money::rub($value) }}
            @endif
        </dd>
    @endforeach
</dl>
