{{-- Счёт сделки строкой в два этажа: номер, под ним светофор (срок — в нём, словами не повторяем) и «ждёт подтверждения»,
     справа остаток или сумма. В одну линию «Счёт № 17» и «Просрочен на 3 дн» на телефоне налезали (11.10.2026). --}}
<a href="/account/money/deals/{{ $invoice->deal_id }}" class="row">
    <x-ui.icon name="file" class="size-5 shrink-0 text-ink-muted"/>
    <span class="min-w-0 flex-1">
        <span class="block whitespace-nowrap">Счёт {{ $invoice->label() }}</span>
        <span class="row-sub"><x-billing.light :invoice="$invoice"/>{{ $invoice->claimed() > 0 ? ', ждёт подтверждения' : '' }}</span>
    </span>
    <span class="nums shrink-0 font-semibold">{{ \App\Support\Money::rub($invoice->remaining() > 0 ? $invoice->remaining() : $invoice->total) }}</span>
</a>
