{{-- Строка таблицы денег по сделкам: документ, менеджер (под ним ТС и срок на телефоне), срок, сумма, остаток.
     Заявка менеджера об оплате подсвечивает строку. Нажатие — окошко. --}}
@props(['invoice'])
@php
    use App\Support\Money; use App\Billing\InvoiceState;
    $i = $invoice; $href = '/work/money/invoices/'.$i->id; $offer = $i->deal?->offer; $claim = $i->claims->isNotEmpty();
    $due = $i->state === InvoiceState::Issued ? $i->due_at->translatedFormat('j M') : $i->state->label();
    $dot = match ($i->light()) { 'open' => 'dot-open', 'urgent', 'danger' => 'dot-urgent', default => '' };
@endphp
<tr id="invoice-{{ $i->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0" class="{{ $claim ? 'bg-urgent-soft' : ($i->isOwed() ? 'text-urgent' : '') }}">
    <td class="nums text-[11px] text-ink-dim sm:text-[13px]">{{ $i->isOwed() ? '→ вознагр.' : $i->label() }}</td>
    <td class="grow">
        <span class="block truncate">{{ $i->deal?->buyer?->name ?? $i->party->name }}<span class="hidden text-ink-muted sm:inline"> {{ $offer?->titleWithYear() }}</span>@if ($claim)<span class="ml-1.5 text-[11px] text-urgent">сообщил об оплате</span>@endif</span>
        <span class="block truncate text-[11px] text-ink-dim sm:hidden"><span class="dot {{ $dot }}"></span>{{ $due }} {{ $offer?->titleWithYear() }}</span>
    </td>
    <td class="hidden text-[13px] sm:table-cell"><span class="dot {{ $dot }}"></span>{{ $due }}</td>
    <td class="num nums {{ $i->remaining() > 0 ? 'font-semibold' : '' }}">{{ Money::rub($i->remaining() > 0 && $i->remaining() < $i->total ? $i->remaining() : $i->total) }}</td>
    <td class="num nums hidden sm:table-cell {{ $i->remaining() > 0 ? 'font-semibold' : 'text-ink-dim' }}">{{ $i->remaining() > 0 ? Money::rub($i->remaining()) : '—' }}</td>
</tr>
