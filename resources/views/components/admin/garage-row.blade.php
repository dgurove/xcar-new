{{-- Строка «Работы → Гаража»: название в два этажа — под ним этап цветным словом и дни на нём (у ждущей машины — слово
     вывоза, у проданной — с какого числа; `App\Garage\CarLine`), на телефоне логотип вендора с убытком тем же этажом после
     запятой; пока идёт сделка со страховой — вместо дней на этапе её срок коротким словом (`x-route.term`), без фразы. На телефоне (и в краткой таблице рядом с окном) справа
     одно главное число этапа (`CarLine`: закупочная, к оплате, продана за). От 640 вместо него — столбцы, каждый своё:
     вендор, «Закупочная», «Расходы», «Цена продажи»; шире — «В гараже с». Строка в одну линию: кто платил расходы и
     что с деньгами по продаже — в карточке. Нажатие — карточка с путём, деньгами и расходами. Срок обязывающего, пока сделка не
     заплатила поставщику: горит — меткой у названия, иначе датой вторым этажом. --}}
@props(['car'])
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
    // Срок обязывающего, пока поставщику не заплачено (19.10.2026): горит — красной меткой, иначе датой вторым этажом.
    $binding = $sale && \App\Offers\Binding::matters($offer);
@endphp
<tr data-detail-key="{{ $offer->number }}" id="garage-{{ $offer->number }}">
    <td class="grow">
        <x-ui.row-link :key="$offer->number"><span class="cell-title">{{ $offer->titleWithYear() }}@if ($binding)<x-offer.binding :offer="$offer" tag class="ml-1.5"/>@endif</span></x-ui.row-link>
        <span class="cell-sub"><span><span class="{{ $line->tone }}">{{ $line->word }}</span>, @if ($sale)<x-route.term :position="$sale"/>@else{{ $line->when }}@endif</span>@if ($binding && ! \App\Offers\Binding::word($offer))<span class="nums">обязывающее до {{ \App\Offers\Binding::date($offer->binding_until) }}</span>@endif{{ "" }}@if ($hasRef)<span class="fit-core sm:hidden"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref"/></span>@endif</span>
    </td>
    <td class="hidden sm:table-cell"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref" cell/></td>
    <td class="num nums cell-dim hidden xl:table-cell col-detail-hide">{{ $car->taken_at?->translatedFormat('j M') }}</td>
    <td class="num nums hidden sm:table-cell">@if ($price){{ Money::nums($price) }}@endif</td>
    <td class="num nums hidden sm:table-cell">@if ($spent > 0){{ Money::nums($spent) }}@endif</td>
    <td class="num nums hidden sm:table-cell">@if ($car->sold_price){{ Money::nums($car->sold_price) }}@endif</td>
    <td class="num nums sm:hidden">@if ($line->caption){{ Money::nums($line->value) }}<span class="cell-sub">{{ $line->caption }}</span>@endif</td>
</tr>
