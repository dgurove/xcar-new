{{-- Платёжка поставщику строкой блока «Поставщику» (16.11.2026): пока шаг оплаты ждёт документ — «Платёжка — Приложить ›» (шторка
     `x-deal.payment-proof-sheet`, та же, что в шаге; приложили — «Оплата приложена, шаг пройден»); после — файлом PDF для поставщика
     с тем, что дальше («ждём подтверждения», «оплачено»). Ждёт из `supplier-rows`: $deal, $proofOpen, $proofReq, $proofFiles,
     $proofSent, $proofState. --}}
@if ($proofOpen)
    <div class="contents" data-controller="sheet">
        <x-deal.sale-row label="Платёжка" :value="$proofFiles->isEmpty() ? 'Приложить' : 'приложена, '.$proofFiles->count()" :tone="$proofFiles->isEmpty() ? 'accent' : null" :icon="$proofFiles->isEmpty() ? 'clip' : null" data-action="sheet#open"/>
        <x-deal.payment-proof-sheet :id="'payment-proof-card-'.$deal->id" :deal="$deal" :requirement="$proofReq"/>
    </div>
@elseif ($proofSent)
    <x-deal.doc-row :href="'/work/deals/'.$deal->id.'/payment-proof.pdf'" title="Платёжка" name="platezhka.pdf">
        @if ($proofState)<span @class(['text-open' => str_starts_with($proofState, 'оплачено')])>{{ $proofState }}</span>@endif
    </x-deal.doc-row>
@endif
