{{-- Строка таблицы гаража на сайте (10.10.2026): название в два этажа — этап цветным словом, когда и чья (`CarLine`, у
     вывоза — слово вывоза и дни). На телефоне справа одно главное число этапа с подписью; от 640 вместо него — столбцы,
     каждый своё: «Закупочная» (менеджеру — закреплённая при подтверждении, 16.10.2026), расходы (без оплаты поставщику — она и есть цена), «Цена продажи» — строка в одну линию,
     что с деньгами по продаже — на странице машины; шире — «В гараже с», сотруднику ещё «Менеджер»; подробная показывает их и на телефоне. Нажатие — страница машины или вывоза
     (`row-go`: карточки рядом у гаража нет). --}}
@props(['offer', 'href', 'word', 'tone' => 'text-ink-muted', 'when' => null, 'value' => null, 'caption' => null, 'who' => null, 'car' => null, 'staff' => false])
@php use App\Support\Money; $spent = $car?->extraSpent(! $staff && $car->forBuyer() ? \App\Garage\Payer::Manager : null) ?? 0; $price = $car?->priceFor($staff); @endphp
<tr id="garage-{{ $offer->number }}">
    <td class="grow">
        <a href="{{ $href }}" class="row-link"><span class="cell-title">{{ $offer->titleWithYear() }}<x-car.keys :count="$offer->keys_count"/></span></a>
        <span class="cell-sub"><span><span class="{{ $tone }}">{{ $word }}</span>{{ $when ? ', '.$when : '' }}@if ($who)<span class="md:hidden">, {{ $who }}</span>@endif</span></span>
    </td>
    @if ($staff)<td class="cell-dim hidden md:table-cell">{{ $car?->manager?->shortName() ?? $who }}</td>@endif
    <td class="num nums cell-dim hidden lg:table-cell col-detail-hide">{{ $car?->taken_at?->translatedFormat('j M') }}</td>
    <td class="num nums hidden sm:table-cell">@if ($price){{ Money::exact($price) }}@endif</td>
    <td class="num nums hidden sm:table-cell">@if ($spent > 0){{ Money::exact($spent) }}@endif</td>
    <td class="num nums hidden sm:table-cell">@if ($car?->sold_price){{ Money::exact($car->sold_price) }}@endif</td>
    <td class="num nums sm:hidden">@if ($caption){{ Money::exact($value) }}<span class="cell-sub">{{ $caption }}</span>@endif</td>
</tr>
