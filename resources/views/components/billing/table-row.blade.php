{{-- Строка таблицы счетов: номер, контрагент, за что, срок со светофором, сумма, остаток. Нажатие — окошко. --}}
@props(['invoice'])
@php use App\Support\Money; $i = $invoice; $href = '/money/invoices/'.$i->id; @endphp
<tr id="invoice-{{ $i->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0" class="{{ $i->isOwed() ? 'text-urgent' : '' }}">
    <td class="nums text-[11px] text-ink-dim sm:text-[13px]">{{ $i->isOwed() ? '→ ' : '' }}{{ $i->label() }}</td>
    {{-- На телефоне срок и «за что» — под контрагентом, столбцы «Срок» и «Остаток» от 640. --}}
    @php $due = $i->state === \App\Billing\InvoiceState::Issued ? $i->due_at->translatedFormat('j M') : $i->state->label(); $dot = match ($i->light()) { 'open' => 'dot-open', 'urgent', 'danger' => 'dot-urgent', default => '' }; @endphp
    <td class="grow">
        <span class="block truncate">{{ $i->party->name }}<span class="hidden text-ink-muted sm:inline"> {{ $i->vehicle ? $i->vehicle->titleWithYear() : ($i->deal ? 'сделка' : $i->kind->label()) }}</span></span>
        <span class="block truncate text-[11px] text-ink-dim sm:hidden"><span class="dot {{ $dot }}"></span>{{ $due }} {{ $i->vehicle ? $i->vehicle->titleWithYear() : $i->kind->label() }}</span>
    </td>
    <td class="hidden text-[13px] sm:table-cell"><span class="dot {{ $dot }}"></span>{{ $due }}</td>
    <td class="num nums {{ $i->remaining() > 0 ? 'font-semibold' : '' }}">{{ Money::rub($i->remaining() > 0 && $i->remaining() < $i->total ? $i->remaining() : $i->total) }}</td>
    <td class="num nums hidden sm:table-cell {{ $i->remaining() > 0 ? 'font-semibold' : 'text-ink-dim' }}">{{ $i->remaining() > 0 ? Money::rub($i->remaining()) : '—' }}</td>
</tr>
