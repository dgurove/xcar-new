{{-- Строка таблицы денег по сделкам — как счета стоянки: менеджер (или его покупатель), под ним срок
     светофором, номер, ТС и «сообщил об оплате»; от 640 номер, ТС и срок встают столбцами. Вознаграждение к
     выплате — «к выплате» словом. Справа сумма, у частичной — остаток. Нажатие — окошко. --}}
@props(['invoice'])
@php
    use App\Billing\InvoiceState;
    use App\Support\Money;
    $i = $invoice;
    $href = '/work/money/invoices/'.$i->id;
    $offer = $i->deal?->offer;
    $claim = $i->claims->isNotEmpty();
    $due = $i->state === InvoiceState::Issued ? 'до '.$i->due_at->translatedFormat('j M') : mb_strtolower($i->state->label());
    $tone = match ($i->light()) { 'danger' => 'text-danger', 'urgent' => 'text-urgent', 'open' => 'text-accent-text', default => '' };
    $left = $i->remaining();
@endphp
<tr id="invoice-{{ $i->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0">
    <td class="grow">
        <span class="cell-title">@if ($i->deal?->buyer){{ $i->deal->buyer->name }}@else<x-vendor.name :party="$i->party"/>@endif</span>
        <span class="cell-sub">
            @if ($claim)<span class="text-urgent">сообщил об оплате</span>@endif
            @if ($i->isOwed())<span class="text-urgent">к выплате</span>@endif
            <span class="sm:hidden {{ $tone }}">{{ $due }}</span>
            <span class="sm:hidden">{{ $i->isOwed() ? 'вознаграждение' : $i->label() }}</span>
            @if ($offer)<span class="sm:hidden">{{ $offer->titleWithYear() }}</span>@endif
        </span>
    </td>
    <td class="cell-dim hidden sm:table-cell">{{ $i->isOwed() ? 'вознаграждение' : $i->label() }}</td>
    <td class="cell-dim col-peek-hide hidden lg:table-cell"><span class="block max-w-56 truncate">{{ $offer?->titleWithYear() }}</span></td>
    <td class="hidden sm:table-cell {{ $tone }}">{{ $due }}</td>
    <td class="num nums">
        {{ Money::nums($i->isPartial() ? $left : $i->total) }}
        @if ($i->isPartial())<span class="cell-sub sm:hidden">из {{ Money::nums($i->total) }}</span>@endif
    </td>
    <td class="cell-dim num nums hidden sm:table-cell">@if ($left > 0 && $i->state === InvoiceState::Issued){{ Money::nums($left) }}@endif</td>
</tr>
