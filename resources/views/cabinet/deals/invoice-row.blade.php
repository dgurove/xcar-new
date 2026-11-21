{{-- Счёт сделки строкой в два этажа: номер, под ним светофор (срок — в нём, словами не повторяем) и «ждёт подтверждения»,
     справа остаток или сумма. В одну линию «Счёт № 17» и «Просрочен на 3 дн» на телефоне налезали (11.10.2026). Нажатие —
     шторка счёта на этой же странице (`cabinet.deals.invoice-sheet`, 19.10.2026: отдельного расчёта больше нет). --}}
<div data-controller="sheet" class="contents">
    <button type="button" class="row w-full text-left" data-action="sheet#open">
        <x-ui.icon name="file" class="size-5 shrink-0 text-ink-muted"/>
        <span class="min-w-0 flex-1">
            {{-- Комиссия без счёта (18.11.2026) — «Оплата подбора», без номера и PDF. --}}
            <span class="block whitespace-nowrap">{{ $invoice->isDocument() ? 'Счёт '.$invoice->label() : \App\Billing\PayOffer::name($invoice) }}</span>
            <span class="row-sub"><x-billing.light :invoice="$invoice"/>{{ $invoice->claimed() > 0 ? ', ждёт подтверждения' : '' }}</span>
        </span>
        <span class="nums shrink-0 font-semibold">{{ \App\Support\Money::rub($invoice->remaining() > 0 ? $invoice->remaining() : $invoice->total) }}</span>
        <x-ui.chevron/>
    </button>
    @include('cabinet.deals.invoice-sheet', ['invoice' => $invoice])
    {{-- «Скачать PDF» — под строкой на виду, а не последней строкой шторки (15.11.2026). --}}
    @if ($invoice->hasPdf() && $invoice->state !== \App\Billing\InvoiceState::Void)<x-billing.pdf-button :invoice="$invoice" :url="'/account/invoices/'.$invoice->id.'/pdf'.$invoice->pdfStamp()"/>@endif
</div>
