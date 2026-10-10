{{-- Строка таблицы гаража на сайте (10.10.2026): название в два этажа — этап цветным словом, когда и чья (`CarLine`, у
     вывоза — слово вывоза и дни), справа главное число этапа с подписью. От 640 — расходы, шире — «В гараже с» и «Цена
     продажи», сотруднику ещё «Менеджер»; подробная показывает их и на телефоне. Нажатие — страница машины или вывоза
     (`row-go`: карточки рядом у гаража нет). --}}
@props(['offer', 'href', 'word', 'tone' => 'text-ink-muted', 'when' => null, 'value' => null, 'caption' => null, 'who' => null, 'car' => null, 'staff' => false])
@php use App\Support\Money; $spent = $car?->spent() ?? 0; @endphp
<tr id="garage-{{ $offer->number }}">
    <td class="grow">
        <a href="{{ $href }}" class="row-link"><span class="cell-title">{{ $offer->titleWithYear() }}</span></a>
        <span class="cell-sub"><span><span class="{{ $tone }}">{{ $word }}</span>{{ $when ? ', '.$when : '' }}@if ($who)<span class="md:hidden">, {{ $who }}</span>@endif</span></span>
    </td>
    @if ($staff)<td class="cell-dim hidden md:table-cell">{{ $who }}</td>@endif
    <td class="num nums cell-dim hidden lg:table-cell col-detail-hide">{{ $car?->taken_at?->translatedFormat('j M') }}</td>
    <td class="num nums hidden sm:table-cell">@if ($spent > 0){{ Money::exact($spent) }}@endif</td>
    <td class="num nums hidden lg:table-cell">@if ($car?->sold_price){{ Money::exact($car->sold_price) }}@endif</td>
    <td class="num nums">@if ($caption){{ Money::exact($value) }}<span class="cell-sub">{{ $caption }}</span>@endif</td>
</tr>
