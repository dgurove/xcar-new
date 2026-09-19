{{-- Счёт плиткой: сетка областей карточки — сумма крупно на месте кадра, контрагент заголовком, светофор и ТС метками, действие. --}}
@props(['invoice'])
@php use App\Support\Money; use App\Billing\InvoiceState; $i = $invoice; $href = '/money/invoices/'.$i->id; @endphp
<article id="invoice-{{ $i->id }}" class="card rise group">
    <a href="{{ $href }}" class="card-media card-media--blank flex items-center justify-center" tabindex="-1">
        <span class="nums text-[28px] font-semibold {{ $i->isOwed() ? 'text-urgent' : '' }}">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
    </a>
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="block min-w-0 flex-1 text-[17px] leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $i->party->name }}</span></a>
        </div>
        <div class="card-marks">
            <span class="mark mark-glass nums">{{ $i->isOwed() ? 'мы должны' : $i->label() }}</span>
            @if ($i->isPartial())<span class="mark mark-glass nums">из {{ Money::rub($i->total) }}</span>@endif
        </div>
    </div>
    <div class="card-extra">
        <x-billing.light :invoice="$i"/>
        <span class="tag">{{ $i->kind->label() }}</span>
        @if ($i->vehicle)<span class="tag">{{ $i->vehicle->titleWithYear() }}</span>@endif
    </div>
    <div class="card-place"><span class="nums text-sm text-ink-dim">{{ $i->issued_at->translatedFormat('j M Y') }}</span></div>
    <div class="card-action"><a href="{{ $href }}" class="btn btn-s btn-quiet w-full whitespace-nowrap">{{ $i->state === InvoiceState::Issued && $i->remaining() > 0 ? ($i->isOwed() ? 'Перечислить' : 'Оплатить') : 'Открыть' }}</a></div>
</article>
