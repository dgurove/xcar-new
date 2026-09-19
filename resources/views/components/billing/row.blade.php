{{-- Счёт строкой: контрагент, за что, светофор, сумма. --}}
@props(['invoice'])
@php use App\Support\Money; $i = $invoice; @endphp
<a href="/money/invoices/{{ $i->id }}" class="row">
    <div class="min-w-0 flex-1">
        <div class="flex items-baseline gap-2"><span class="truncate font-medium">{{ $i->party->name }}</span><span class="nums shrink-0 text-sm text-ink-muted">{{ $i->isOwed() ? 'мы должны' : $i->label() }}</span></div>
        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
            <x-billing.light :invoice="$i"/>
            <span class="chip">{{ $i->kind->label() }}</span>
            @if ($i->vehicle)<span class="tag">{{ $i->vehicle->titleWithYear() }}</span>@endif
        </div>
    </div>
    <div class="shrink-0 text-right">
        <div class="nums font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</div>
        @if ($i->isPartial())<div class="nums text-xs text-ink-muted">из {{ Money::rub($i->total) }}</div>@endif
    </div>
</a>
