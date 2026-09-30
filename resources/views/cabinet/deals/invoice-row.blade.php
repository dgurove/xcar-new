{{-- Счёт сделки строкой: номер, светофор (срок — в нём, словами не повторяем), остаток или сумма. --}}
<a href="/account/money/deals/{{ $invoice->deal_id }}" class="row">
    <x-ui.icon name="file" class="size-5 shrink-0 text-ink-muted"/>
    <span class="min-w-0 flex-1"><span class="whitespace-nowrap">Счёт {{ $invoice->label() }}</span>{{ $invoice->claimed() > 0 ? ', ждёт подтверждения' : '' }}</span>
    <x-billing.light :invoice="$invoice"/>
    <span class="nums font-semibold">{{ \App\Support\Money::rub($invoice->remaining() > 0 ? $invoice->remaining() : $invoice->total) }}</span>
</a>
