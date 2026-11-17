{{-- Строка таблицы «Оплат» (`admin/money/tab-table`): счёт или заявка «оплатил». На ПК — факт на столбец, в одну линию; на
     телефоне — название в два этажа (что сейчас и ТС под ним), справа сумма. Столбцы — `MoneyController::table`. now / tone —
     что со счётом словом и цветом (`MoneyRows`: срок, ссылка, как заплатили), manager — менеджер сделки (аватар и имя, как в
     «Сделках»), amount / amountTone — число справа. paid — доля оплаченного у частичной: на ПК серым «оплачено 96 %» за
     статусом (17.11.2026: было «оплачено 1 680 000 ₽ из 1 750 000 ₽» рядом с остатком — три числа про один счёт; полоска под
     суммой читалась подчёркиванием), суммы — в карточке. link — что со ссылкой счёта [слово, тон] (`MoneyRows::linkWord`)
     хвостом статуса; на телефоне — только тревожное (не прошла, открыли и бросили). Название — уже коротким видом (`PartyName`). --}}
@props(['table', 'tab', 'invoice', 'title', 'now', 'tone' => '', 'manager' => null, 'amount', 'amountTone' => '', 'paid' => null, 'link' => null])
@php
    use App\Http\Admin\MoneyRows as R; use App\Support\Money;
    $what = R::what($invoice);
    $car = $invoice->carOffer()?->crmPath();
    // 99 % — потолок: «100 %» у неоплаченного остатка врёт.
    $share = $paid !== null ? max(1, min(99, (int) floor($paid * 100))) : null;
    // Хвост статуса — второстепенный кусок (`.cell-sub`): ширину столбца не задаёт (`Support\Table\Widths`), при нехватке места
    // встаёт второй строкой, а не раздвигает таблицу вбок. Запятая — у предыдущего куска.
    $tail = array_values(array_filter([$share ? ['оплачено '.$share.' %', 'text-ink-muted'] : null, $link]));
@endphp
<tr data-detail-key="{{ $invoice->id }}" data-search-row>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$invoice->id" :with="['tab' => $tab]"><span class="cell-title"><span class="cell-name">{{ $title }}</span>@if ($table->has('amount'))<span class="line-end sm:hidden {{ $amountTone }}">{{ Money::nums($amount) }}</span>@endif</span></x-ui.row-link>
        <span class="cell-sub sm:hidden">@if ($now)<span class="{{ $tone }}">{{ $now }}</span>@endif @if ($link && $link[1] !== 'text-ink-muted')<span class="{{ $link[1] }}">{{ $link[0] }}</span>@endif @if ($what && $car)<a href="{{ $car }}" class="cell-car" data-turbo-frame="_top">{{ $what }}</a>@elseif ($what)<span class="cell-car">{{ $what }}</span>@endif</span>
    </td>
        @break
        @case('manager')
    <td class="{{ $col->classes() }}"><x-cell.person :user="$manager" from="lg"/></td>
        @break
        @case('car')
    {{-- ТС — переход в сделку или гараж (17.11.2026, владелец: «очень не хватает перехода в сделки из оплат»). --}}
    <td class="{{ $col->classes() }}">@if ($what && $car)<a href="{{ $car }}" class="block truncate hover:underline" title="{{ $what }}" data-turbo-frame="_top">{{ $what }}</a>@elseif ($what)<span class="block truncate" title="{{ $what }}">{{ $what }}</span>@elseif ($invoice->isService())<span class="text-ink-dim">не указано</span>@endif</td>
        @break
        @case('number')
    <td class="{{ $col->classes() }}">{{ $invoice->label() }}</td>
        @break
        @case('status')
    <td class="{{ $col->classes() }} {{ $tone }}">{{ $now }}@if ($tail), <span class="cell-sub">@foreach ($tail as $t)<span class="{{ $t[1] }}">{{ $t[0] }}{{ $loop->last ? '' : ',' }}</span>@endforeach</span>@endif</td>
        @break
        @case('created')
    <td class="{{ $col->classes() }}">{{ \App\Support\Ago::html($invoice->created_at, true) }}</td>
        @break
        @case('amount')
    {{-- Телефон: сумма — концом строки имени (15.11.2026, владелец: «не резать, если справа есть место»). --}}
    <td class="{{ $col->classes() }} phone-off {{ $amountTone }}">{{ Money::nums($amount) }}</td>
        @break
        @endswitch
    @endforeach
</tr>
