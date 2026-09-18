{{-- Строка таблицы счетов: номер, контрагент, за что, срок со светофором, сумма, остаток. Нажатие — окошко. --}}
@props(['invoice'])
@php use App\Support\Money; $i = $invoice; $href = '/money/invoices/'.$i->id; @endphp
<tr id="invoice-{{ $i->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0" class="{{ $i->isOwed() ? 'text-urgent' : '' }}">
    <td class="nums text-[11px] text-ink-dim sm:text-[13px]">{{ $i->isOwed() ? '→ ' : '' }}{{ $i->label() }}</td>
    <td class="grow">{{ $i->party->name }}<span class="hidden text-ink-muted sm:inline"> {{ $i->vehicle ? $i->vehicle->titleWithYear() : ($i->deal ? 'сделка' : $i->kind->label()) }}</span></td>
    <td class="text-[11px] sm:text-[13px]"><span class="dot {{ match ($i->light()) { 'open' => 'dot-open', 'urgent', 'danger' => 'dot-urgent', default => '' } }}"></span>{{ $i->state === \App\Billing\InvoiceState::Issued ? $i->due_at->translatedFormat('j M') : $i->state->label() }}</td>
    <td class="num nums">{{ Money::rub($i->total) }}</td>
    <td class="num nums {{ $i->remaining() > 0 ? 'font-semibold' : 'text-ink-dim' }}">{{ $i->remaining() > 0 ? Money::rub($i->remaining()) : '—' }}</td>
</tr>
