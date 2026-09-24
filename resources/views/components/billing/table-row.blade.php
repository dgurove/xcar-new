{{-- Строка таблицы счетов. Ячейка в два этажа: контрагент, под ним срок светофором («до 25 сен», «оплачен»),
     номер и за что; от 640 номер, «за что» и срок встают своими столбцами. «Мы должны» — словом, у всех ширин.
     Справа сумма (у частично оплаченного — остаток), под ней на телефоне «из N». Нажатие — окошко. --}}
@props(['invoice'])
@php
    use App\Billing\InvoiceState;
    use App\Support\Money;
    $i = $invoice;
    $href = '/money/invoices/'.$i->id;
    $due = $i->state === InvoiceState::Issued ? 'до '.$i->due_at->translatedFormat('j M') : mb_strtolower($i->state->label());
    $tone = match ($i->light()) { 'danger' => 'text-danger', 'urgent' => 'text-urgent', 'open' => 'text-accent-text', default => '' };
    $what = $i->vehicle ? $i->vehicle->titleWithYear() : ($i->deal ? 'сделка' : $i->kind->label());
    $left = $i->remaining();
@endphp
<tr id="invoice-{{ $i->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0">
    <td class="grow">
        <span class="cell-title">{{ $i->party->name }}</span>
        <span class="cell-sub">
            @if ($i->isOwed())<span class="text-urgent">мы должны</span>@endif
            <span class="sm:hidden {{ $tone }}">{{ $due }}</span>
            <span class="sm:hidden">{{ $i->label() }}</span>
            <span class="sm:hidden">{{ $what }}</span>
        </span>
    </td>
    <td class="cell-dim hidden sm:table-cell">{{ $i->label() }}</td>
    <td class="cell-dim col-peek-hide hidden lg:table-cell"><span class="block max-w-56 truncate">{{ $what }}</span></td>
    <td class="hidden sm:table-cell {{ $tone }}">{{ $due }}</td>
    <td class="num nums">
        {{ Money::nums($i->isPartial() ? $left : $i->total) }}
        @if ($i->isPartial())<span class="cell-sub sm:hidden">из {{ Money::nums($i->total) }}</span>@endif
    </td>
    <td class="cell-dim num nums hidden sm:table-cell">@if ($left > 0 && $i->state === InvoiceState::Issued){{ Money::nums($left) }}@endif</td>
</tr>
