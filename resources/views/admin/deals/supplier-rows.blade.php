{{-- Блок «Поставщику» / «Собственнику» «Продажи» CRM — счёт поставщика и платёжка строками той же группы, под «Реквизитами»
     (16.11.2026, владелец: «блок „Оплата поставщику“ не нужен, если поставщику платит не мы, а покупатель — счёт и платёжку оттуда
     в блок „Продажа“, в подблок „Поставщику“»). У ДКП — ещё взаимозачёт со страховой. Ждёт: $deal. --}}
@php
    $offer = $deal->offer;
    $bill = \App\Offers\SupplierInvoice::shown($offer)->first();
    $proofStage = $offer?->position(\App\Workflow\Track::Sale)?->stage;
    $proofReq = $deal->openRequirement;
    $proofOpen = auth()->user()?->canEditDeals() && $proofReq && $proofReq->asks === \App\Workflow\Asks::Document && $proofStage?->isPayStep()
        && $proofReq->stage_id === $proofStage->id && $deal->paysSupplierOn($proofStage);
    $proofFiles = $proofOpen ? $proofReq->getMedia('files') : collect();
    $proofSent = ! $proofOpen && \App\Offers\Documents\PaymentProofPdf::files($deal)->isNotEmpty();
    $paid = $deal->supplierPaid();
    $proofState = match (true) {
        $paid => 'оплачено'.($deal->supplier_paid_at ? ' '.\App\Support\Ago::day($deal->supplier_paid_at) : ''),
        $proofSent => 'ждём подтверждения',
        default => null,
    };
@endphp
@if ($bill)
    <x-deal.doc-row :href="'/files/'.$bill->id" title="Счёт поставщика" :date="\App\Support\Ago::day($bill->created_at)" :name="$bill->file_name" :mime="$bill->mime_type" :bytes="$bill->size">
        <span class="nums whitespace-nowrap">{{ $deal->isDirect() && $deal->owner_price === null ? 'уточним' : \App\Support\Money::rub((int) $deal->supplierPayment()) }}</span>
    </x-deal.doc-row>
@endif
@include('admin.deals.supplier-payment-proof')
@if (! $proofOpen && ! $proofSent && $paid)
    <div class="row sale-row"><span class="sale-row-label">Оплата поставщику</span><span class="sale-row-value text-open">{{ $proofState }}</span><x-ui.chevron-space/></div>
@endif
@if ($deal->schemeOf() === \App\Offers\DealScheme::OwnerDkp && $deal->offset())
    {{-- Нажимается — шторка «Взаимозачёт со страховой» (`x-deal.offset-sheet`, 23.11.2026), у тех, кто правит деньги. --}}
    @if ($offsetEdit ?? false)
        <button type="button" class="row sale-row" data-controller="emit" data-action="emit#send" data-emit-event-param="offset-{{ $deal->id }}:open"><span class="sale-row-label">Взаимозачёт со страховой</span><span class="sale-row-value nums">−{{ \App\Support\Money::rub($deal->offset()) }}</span><x-ui.chevron/></button>
    @else
        <div class="row sale-row"><span class="sale-row-label">Взаимозачёт со страховой</span><span class="sale-row-value nums">−{{ \App\Support\Money::rub($deal->offset()) }}</span><x-ui.chevron-space/></div>
    @endif
@endif
