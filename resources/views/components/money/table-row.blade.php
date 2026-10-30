{{-- Строка таблицы «Оплат» (`admin/money/tab-table`): счёт или заявка «оплатил». На ПК — факт на столбец, в одну линию; на
     телефоне — название в два этажа (что сейчас и ТС под ним), справа сумма. Столбцы — `MoneyController::table`. now / tone —
     что со счётом словом и цветом (`MoneyRows`), after — хвост слова на ПК [текст, цвет] («, оплачено 50 000 из 80 000»),
     phone — лишнее на телефоне [текст, цвет], manager — менеджер сделки, amount / amountTone — число справа. --}}
@props(['table', 'tab', 'invoice', 'title', 'now', 'tone' => '', 'after' => null, 'phone' => null, 'manager' => null, 'amount', 'amountTone' => ''])
@php
    use App\Http\Admin\MoneyRows as R;
    $what = R::what($invoice);
@endphp
<tr data-detail-key="{{ $invoice->id }}" data-search-row>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$invoice->id" :with="['tab' => $tab]"><span class="cell-title"><span class="cell-name">{{ $title }}</span></span></x-ui.row-link>
        <span class="cell-sub sm:hidden"><span class="{{ $tone }}">{{ $now }}</span>@if ($what)<span class="cell-car">{{ $what }}</span>@endif @if ($phone)<span class="{{ $phone[1] }}">{{ $phone[0] }}</span>@endif</span>
    </td>
        @break
        @case('manager')
    <td class="{{ $col->classes() }}">@if ($manager)<x-ui.person :user="$manager"/>@endif</td>
        @break
        @case('car')
    <td class="{{ $col->classes() }}">{{ $what }}</td>
        @break
        @case('number')
    <td class="{{ $col->classes() }}">{{ $invoice->label() }}</td>
        @break
        @case('status')
    <td class="{{ $col->classes() }} {{ $tone }}">{{ $now }}@if ($after)<span class="{{ $after[1] }}">, {{ $after[0] }}</span>@endif</td>
        @break
        @case('created')
    <td class="{{ $col->classes() }}">{{ \App\Support\Ago::cell($invoice->created_at, true) }}</td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }} {{ $amountTone }}">{{ \App\Support\Money::nums($amount) }}</td>
        @break
        @endswitch
    @endforeach
</tr>
