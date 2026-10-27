{{-- Строка «Работы → Гаража»: название в два этажа — под ним этап цветным словом и дни на нём (у ждущей машины — слово
     вывоза, у проданной — с какого числа; `App\Garage\CarLine`), на телефоне логотип вендора с убытком тем же этажом после
     запятой; пока идёт сделка со страховой — вместо дней на этапе её срок коротким словом (`x-route.term`), без фразы. На телефоне (и в краткой таблице рядом с окном) справа
     одно главное число этапа (`CarLine`: закупочная, к оплате, продана за). От 640 вместо него — столбцы, каждый своё:
     вендор, «Закупочная», «Расходы», «Цена продажи»; шире — «В гараже с». Строка в одну линию: кто платил расходы и
     что с деньгами по продаже — в карточке. Нажатие — карточка с путём, деньгами и расходами. Срок обязывающего, пока сделка не
     заплатила поставщику, — меткой у названия, только когда горит. --}}
@props(['car', 'table'])
@php
    use App\Support\Money;
    $offer = $car->offer;
    $line = \App\Garage\CarLine::for($car, auth()->user(), crm: true);
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
    $hasRef = $vendor || $offer->claim_ref;
    // Оплата поставщику — та же закупочная, в расходах её второй раз нет.
    $spent = $car->extraSpent();
    $price = $car->price();
    // Сделка со страховой идёт — её шаг и чей ход строкой ниже, как в «Сделках».
    $sale = $car->dealOpen() ? $offer->positions->first(fn ($p) => $p->track === \App\Workflow\Track::Sale) : null;
    // Срок обязывающего, пока поставщику не заплачено (19.10.2026): только горящий — красной меткой у названия.
    $binding = $sale && \App\Offers\Binding::matters($offer);
@endphp
<tr data-detail-key="{{ $offer->number }}" id="garage-{{ $offer->number }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$offer->number"><span class="cell-title">{{ $offer->titleWithYear() }}@if ($binding)<x-offer.binding :offer="$offer" icon/>@endif</span></x-ui.row-link>
        <span class="cell-sub"><span><span class="{{ $line->tone }}">{{ $line->word }}</span>, @if ($sale)<x-route.term :position="$sale"/>@else{{ $line->when }}@endif</span>@if ($hasRef)<span class="fit-core sm:hidden"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref"/></span>@endif</span>
    </td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref" cell/></td>
        @break
        @case('taken')
    <td class="{{ $col->classes() }}">{{ $car->taken_at?->translatedFormat('j M') }}</td>
        @break
        @case('price')
    <td class="{{ $col->classes() }}">@if ($price){{ Money::nums($price) }}@endif</td>
        @break
        @case('spent')
    <td class="{{ $col->classes() }}">@if ($spent > 0){{ Money::nums($spent) }}@endif</td>
        @break
        @case('sold')
    <td class="{{ $col->classes() }}">@if ($car->sold_price){{ Money::nums($car->sold_price) }}@endif</td>
        @break
        @case('sum')
    <td class="{{ $col->classes() }}">@if ($line->caption){{ Money::nums($line->value) }}<span class="cell-sub">{{ $line->caption }}</span>@endif</td>
        @break
        @endswitch
    @endforeach
</tr>
