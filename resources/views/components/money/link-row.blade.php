{{-- Строка «По ссылкам» таблицей (`admin/money/links-table`): ссылка на оплату (`MoneyLinks`). На ПК — кто платит, ТС с
     номером серым, статус коротким словом цветом, когда и кто взял, сумма; на телефоне — как строка банка: имя и сумма, под
     ними ТС и статус. Имя — коротким видом (`PartyName`). time — время в группе дня, иначе дата. Столбцы — `MoneyController::table('links')`. --}}
@props(['table', 'link', 'time' => false])
@php
    use App\Http\Admin\MoneyLinks as L; use App\Billing\Acquiring\PayLinkState;
    [$car, $number] = L::car($link);
    [$state, $tone] = L::short($link);
    $paid = $link->state === PayLinkState::Paid;
@endphp
<tr data-detail-key="{{ $link->id }}" data-search-row @class(['opacity-50' => $link->state === PayLinkState::Canceled])>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$link->id" :with="['tab' => 'links']"><span class="cell-title"><span class="cell-name">{{ \App\Billing\PartyName::short($link->payerName()) }}</span>@if ($table->has('amount'))<span class="line-end sm:hidden nums {{ $paid ? 'text-open' : '' }}">{{ \App\Support\Money::nums($link->amount) }}</span>@endif</span></x-ui.row-link>
        <span class="cell-sub cell-sub--wrap sm:hidden"><span class="min-w-0 truncate">@if ($car){{ $car }}@if ($number)<span class="nums">, № {{ $number }}</span>@endif @else{{ L::who($link) }}@endif</span>@if ($table->has('amount'))<span class="line-end {{ $tone }}">{{ $state }}</span>@endif</span>
    </td>
        @break
        @case('car')
    {{-- Одной строкой (17.11.2026: «Lynk & Co / 900 / 2610051187» в три этажа рвали таблицу): номер серым следом, длинное — «…». --}}
    <td class="{{ $col->classes() }}">@if ($car)<span class="block truncate" title="{{ $car }}{{ $number ? ' '.$number : '' }}">{{ $car }}@if ($number)<span class="nums text-ink-dim"> {{ $number }}</span>@endif</span>@else<span class="text-ink-dim">не указано</span>@endif</td>
        @break
        @case('status')
    <td class="{{ $col->classes() }} {{ $tone }}">{{ $state }}</td>
        @break
        @case('created')
    <td class="{{ $col->classes() }}">{{ $time ? $link->created_at->format('H:i') : \App\Support\Ago::cell($link->created_at, true) }}<span class="text-ink-dim">, {{ L::who($link) }}</span></td>
        @break
        @case('amount')
    {{-- Телефон: сумма и состояние ссылки — концами строк имени и машины (15.11.2026, владелец: «не резать, если справа есть место»). --}}
    <td class="{{ $col->classes() }} phone-off">
        <span class="cell-title nums {{ $paid ? 'text-open' : '' }}">{{ \App\Support\Money::nums($link->amount) }}</span>
        <span class="cell-sub sm:hidden {{ $tone }}">{{ $state }}</span>
    </td>
        @break
        @endswitch
    @endforeach
</tr>
