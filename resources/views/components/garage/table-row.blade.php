{{-- Строка таблицы гаража на сайте (10.10.2026): этап цветным словом и когда (`CarLine`, у вывоза — слово вывоза и дни) —
     от 640 своим столбцом «Этап» (29.10.2026), на телефоне вторым этажом названия вместе с тем, чья. На телефоне справа одно главное число этапа с подписью; от 640 вместо него — столбцы,
     каждый своё: «Закупочная» (менеджеру — закреплённая при подтверждении, 16.10.2026), расходы (без оплаты поставщику — она и есть цена), «Цена продажи» — строка в одну линию,
     что с деньгами по продаже — на странице машины; шире — «В гараже с», сотруднику ещё «Менеджер»; подробная показывает их и на телефоне. Нажатие — страница машины или вывоза
     (`row-go`: карточки рядом у гаража нет). --}}
@props(['offer', 'table', 'href', 'word', 'tone' => 'text-ink-muted', 'when' => null, 'value' => null, 'caption' => null, 'who' => null, 'car' => null, 'staff' => false])
@php use App\Support\Money; $spent = $car?->extraSpent(! $staff && $car->forBuyer() ? \App\Garage\Payer::Manager : null) ?? 0; $price = $car?->priceFor($staff); @endphp
<tr id="garage-{{ $offer->number }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <a href="{{ $href }}" class="row-link"><span class="cell-title">{{ $offer->titleWithYear() }}<x-car.keys :count="$offer->keys_count"/></span></a>
        <span class="cell-sub"><span class="sm:hidden"><span class="{{ $tone }}">{{ $word }}</span>{{ $when ? ', '.$when : '' }}</span>@if ($who)<span class="md:hidden"><span class="sm:hidden">, </span>{{ $who }}</span>@endif</span>
    </td>
        @break
        @case('manager')
    <td class="{{ $col->classes() }}">{{ $car?->manager?->shortName() ?? $who }}</td>
        @break
        @case('stage')
    <td class="{{ $col->classes() }}"><span class="whitespace-normal"><span class="{{ $tone }}">{{ $word }}</span>{{ $when ? ', '.$when : '' }}</span></td>
        @break
        @case('taken')
    <td class="{{ $col->classes() }}">{{ \App\Support\Ago::cell($car?->taken_at) }}</td>
        @break
        @case('price')
    <td class="{{ $col->classes() }}">@if ($price){{ Money::exact($price) }}@endif</td>
        @break
        @case('spent')
    <td class="{{ $col->classes() }}">@if ($spent > 0){{ Money::exact($spent) }}@endif</td>
        @break
        @case('sold')
    <td class="{{ $col->classes() }}">@if ($car?->sold_price){{ Money::exact($car->sold_price) }}@endif</td>
        @break
        @case('sum')
    <td class="{{ $col->classes() }}">@if ($caption){{ Money::exact($value) }}<span class="cell-sub">{{ $caption }}</span>@endif</td>
        @break
        @endswitch
    @endforeach
</tr>
