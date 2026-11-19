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
{{-- Договор от страховой («страховой напрямую») — над «Подписанным договором»: прислали — файлом со «Скачать», нет — «Приложить». --}}
@if (\App\Offers\SupplierContract::applies($deal))
    @php $supplierContract = \App\Offers\SupplierContract::of($offer); @endphp
    @if ($supplierContract)
        <x-deal.doc-row :href="'/files/'.$supplierContract->id" title="Договор от страховой" :name="$supplierContract->file_name" :mime="$supplierContract->mime_type" :bytes="$supplierContract->size" :file="'Договор от страховой.'.(pathinfo($supplierContract->file_name, PATHINFO_EXTENSION) ?: 'pdf')">
            {{ ($supplierContract->getCustomProperty('letter') ? 'из письма ' : 'приложен ').\App\Support\Ago::day($supplierContract->created_at) }}
        </x-deal.doc-row>
    @elseif (auth()->user()?->canEditDeals() && $deal->isActive())
        <form method="post" action="/work/deals/{{ $deal->id }}/supplier-contract" enctype="multipart/form-data" class="contents" data-controller="autosubmit">
            @csrf
            <label class="row sale-row cursor-pointer">
                <span class="sale-row-label">Договор от страховой</span>
                <span class="sale-row-value text-accent-text">Приложить</span>
                <x-ui.icon name="clip" class="size-5 shrink-0 text-ink-dim"/>
                <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.heic,.doc,.docx" class="sr-only" data-action="change->autosubmit#submit">
            </label>
        </form>
    @endif
@endif
@if (! $proofOpen && ! $proofSent && $paid)
    <div class="row sale-row"><span class="sale-row-label">Оплата поставщику</span><span class="sale-row-value text-open">{{ $proofState }}</span><x-ui.chevron-space/></div>
@endif
@if ($deal->schemeOf() === \App\Offers\DealScheme::OwnerDkp && $deal->offset())
    <div class="row sale-row"><span class="sale-row-label">Взаимозачёт со страховой</span><span class="sale-row-value nums">−{{ \App\Support\Money::rub($deal->offset()) }}</span><x-ui.chevron-space/></div>
@endif
