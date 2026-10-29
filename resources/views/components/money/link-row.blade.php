{{-- Строка «По ссылкам» таблицей (`admin/money/links-table`): ссылка на оплату (`MoneyLinks`). На ПК — кто платит, ТС с
     номером серым, статус коротким словом цветом, когда и кто взял, сумма; на телефоне — как строка банка: имя и сумма, под
     ними ТС и статус. time — время в группе дня, иначе дата. Столбцы — `MoneyController::table('links')`. --}}
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
        <x-ui.row-link :key="$link->id" :with="['tab' => 'links']"><span class="cell-title">{{ $link->payerName() }}</span></x-ui.row-link>
        <span class="cell-sub sm:hidden">@if ($car){{ $car }}@if ($number)<span class="nums">, № {{ $number }}</span>@endif @else{{ L::who($link) }}@endif</span>
    </td>
        @break
        @case('car')
    <td class="{{ $col->classes() }}">@if ($car){{ $car }}@if ($number)<span class="nums text-ink-dim"> {{ $number }}</span>@endif @else<span class="text-ink-dim">не указано</span>@endif</td>
        @break
        @case('status')
    <td class="{{ $col->classes() }} {{ $tone }}">{{ $state }}</td>
        @break
        @case('created')
    <td class="{{ $col->classes() }}">{{ $time ? $link->created_at->format('H:i') : \App\Support\Ago::cell($link->created_at, true) }}<span class="text-ink-dim">, {{ L::who($link) }}</span></td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }}">
        <span class="cell-title nums {{ $paid ? 'text-open' : '' }}">{{ \App\Support\Money::nums($link->amount) }}</span>
        <span class="cell-sub sm:hidden {{ $tone }}">{{ $state }}</span>
    </td>
        @break
        @endswitch
    @endforeach
</tr>
