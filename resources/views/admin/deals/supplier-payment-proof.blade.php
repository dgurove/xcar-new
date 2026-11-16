{{-- Платёжка поставщику строкой «Оплаты поставщику» (16.11.2026): пока шаг оплаты ждёт документ — «Платёжка — Приложить ›» (шторка
     `x-deal.payment-proof-sheet`, та же, что в шаге; приложили — «Оплата приложена, шаг пройден»); после — файлом PDF для поставщика.
     Ждёт из `supplier-payment`: $deal, $proofOpen, $proofReq, $proofFiles, $proofSent. --}}
@if ($proofOpen)
    <div class="contents" data-controller="sheet">
        <x-deal.sale-row label="Платёжка" :value="$proofFiles->isEmpty() ? 'Приложить' : 'приложена, '.$proofFiles->count()" :tone="$proofFiles->isEmpty() ? 'accent' : null" data-action="sheet#open"/>
        <x-deal.payment-proof-sheet :id="'payment-proof-card-'.$deal->id" :deal="$deal" :requirement="$proofReq"/>
    </div>
@elseif ($proofSent)
    <a href="/work/deals/{{ $deal->id }}/payment-proof.pdf" class="row" target="_blank" data-doc="pdf" data-doc-name="Платёжка">
        <x-ui.file-icon name="platezhka.pdf"/>
        <span class="min-w-0 flex-1 truncate">Платёжка, PDF</span>
        <x-ui.chevron/>
    </a>
@endif
