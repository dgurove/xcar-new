{{-- Строка «Работы → Гаража»: название в два этажа — под ним этап цветным словом и дни на нём (у ждущей машины — слово
     вывоза, у проданной — с какого числа; `App\Garage\CarLine`), на телефоне логотип вендора с убытком тем же этажом после
     запятой; пока идёт сделка со страховой — её шаг и чей ход ниже. От 640 — вендор столбцом, расходы (его и наши) и
     главное число этапа: до продажи цена гаража, после — к оплате или цена продажи. От 640 — «Цена гаража» своим столбцом
     (тогда «Сумма» её не повторяет), подробная и широкий ПК — ещё «В гараже с» и «Цена продажи». Нажатие — карточка с путём, деньгами и расходами. --}}
@props(['car'])
@php
    use App\Garage\Payer;
    use App\Support\Money;
    $offer = $car->offer;
    $line = \App\Garage\CarLine::for($car, auth()->user(), crm: true);
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
    $hasRef = $vendor || $offer->claim_ref;
    // Оплата поставщику — та же цена гаража, в расходах её второй раз нет.
    $spent = $car->extraSpent();
    $ours = $car->extraSpent(Payer::Xcar);
    $price = $car->price();
    $repeats = $price && $line->caption === 'цена гаража';
    // Сделка со страховой идёт — её шаг и чей ход строкой ниже, как в «Сделках».
    $sale = $car->dealOpen() ? $offer->positions->first(fn ($p) => $p->track === \App\Workflow\Track::Sale) : null;
@endphp
<tr data-detail-key="{{ $offer->number }}" id="garage-{{ $offer->number }}">
    <td class="grow">
        <x-ui.row-link :key="$offer->number"><span class="cell-title">{{ $offer->titleWithYear() }}</span></x-ui.row-link>
        <span class="cell-sub"><span><span class="{{ $line->tone }}">{{ $line->word }}</span>, {{ $line->when }}</span>@if ($hasRef)<span class="fit-core sm:hidden"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref"/></span>@endif</span>
        @if ($sale)<x-route.clock :position="$sale" side="staff" class="mt-0.5 line-clamp-2 sm:truncate"/>@endif
    </td>
    <td class="hidden sm:table-cell"><span class="vendor-ref">@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif @if ($offer->claim_ref)<x-ui.copy-code :value="$offer->claim_ref"/>@endif</span></td>
    <td class="num nums cell-dim hidden xl:table-cell col-detail-hide">{{ $car->taken_at?->translatedFormat('j M') }}</td>
    <td class="num nums hidden sm:table-cell">@if ($price){{ Money::nums($price) }}@endif</td>
    <td class="num nums hidden sm:table-cell col-detail-hide">@if ($spent > 0){{ Money::nums($spent) }}@if ($ours > 0)<span class="cell-sub">наши {{ Money::nums($ours) }}</span>@endif @endif</td>
    <td class="num nums hidden lg:table-cell col-detail-hide">@if ($car->sold_price){{ Money::nums($car->sold_price) }}@endif</td>
    <td class="num nums">@if ($line->caption)<span @class(['sm:hidden' => $repeats])>{{ Money::nums($line->value) }}<span class="cell-sub">{{ $line->caption }}</span></span>@endif</td>
</tr>
