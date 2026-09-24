{{-- Счёт строкой сгруппированного списка: контрагент, под ним номер, за что и ТС словами; справа сумма, под ней
     светофор словом. --}}
@props(['invoice'])
@php
    use App\Billing\InvoiceState;
    use App\Support\Money;
    $i = $invoice;
    [$word, $tone] = match (true) {
        $i->state === InvoiceState::Void => ['аннулирован', 'text-ink-dim'],
        $i->state === InvoiceState::Paid => ['оплачен', 'text-accent-text'],
        $i->isOverdue() => ['просрочен '.$i->overdueDays().' дн', 'text-danger'],
        $i->isPartial() => ['частично', 'text-urgent'],
        default => ['до '.$i->due_at->translatedFormat('j M'), $i->light() === 'urgent' ? 'text-urgent' : 'text-ink-muted'],
    };
@endphp
<a href="/money/invoices/{{ $i->id }}" class="row">
    <div class="min-w-0 flex-1">
        <div class="truncate"><x-vendor.name :party="$i->party"/></div>
        <div class="row-sub">
            <span class="nums {{ $i->isOwed() ? 'text-urgent' : '' }}">{{ $i->isOwed() ? 'мы должны' : $i->label() }}</span>
            <span>{{ $i->kind->label() }}</span>
            @if ($i->vehicle)<span>{{ $i->vehicle->titleWithYear() }}</span>@endif
        </div>
    </div>
    <div class="shrink-0 text-right">
        <div class="nums">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</div>
        <div class="text-sm {{ $tone }}">{{ $word }}</div>
    </div>
</a>
