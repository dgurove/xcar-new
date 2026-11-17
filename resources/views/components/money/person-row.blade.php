{{-- Строка таблицы страницы человека в «Оплатах» (`admin/money/person`, столбцы — `MoneyController::personTable`): машина, как в
     «Сделках» (`x-cell.title`, вендор с № убытка), что с оплатой словом (`now`, тон `tone`), когда выставлено, сумма и кнопки дела
     самым правым (слот). Нажатие — окно машины рядом; без машины (разовая услуга, вывоз) — карточка счёта в «Оплатах». --}}
@props(['table', 'invoice', 'now' => null, 'tone' => '', 'amount', 'amountTone' => '', 'tab'])
@php
    use App\Support\Money;
    $i = $invoice;
    $offer = $i->carOffer();
    $title = $offer ? null : (\App\Http\Admin\MoneyRows::what($i) ?? \App\Http\Admin\MoneyRows::payoutKind($i));
@endphp
<tr @if ($offer) data-detail-key="{{ $offer->number }}" @endif data-search-row>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        @if ($offer)
            <x-cell.title :key="$offer->number" :offer="$offer" :with="['tab' => $tab]"/>
        @else
            <a href="{{ \App\Http\Admin\MoneyController::url($i) }}" class="cell-title" data-turbo-frame="_top"><span class="cell-name">{{ $title }}</span></a>
        @endif
        <span class="cell-sub sm:hidden">@if ($offer)<x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref"/>@endif @if ($now)<span class="{{ $tone }}">{{ $now }}</span>@endif <span class="nums {{ $amountTone }}">{{ Money::rub($amount) }}</span></span>
    </td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}">@if ($offer)<x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" cell/>@endif</td>
        @break
        @case('status')
    <td class="{{ $col->classes() }} {{ $tone }}">{{ $now }}</td>
        @break
        @case('created')
    <td class="{{ $col->classes() }}">{!! \App\Support\Ago::html($i->created_at, true) !!}</td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }} phone-off {{ $amountTone }}">{{ Money::nums($amount) }}</td>
        @break
        @case('action')
    <td class="{{ $col->classes() }}"><span class="money-acts">{{ $slot }}</span></td>
        @break
        @endswitch
    @endforeach
</tr>
