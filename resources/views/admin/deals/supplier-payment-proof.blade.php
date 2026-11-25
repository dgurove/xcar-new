{{-- Платёжка поставщику строкой блока «Поставщику» (16.11.2026): пока шаг оплаты ждёт документ — «Платёжка — Приложить ›» (шторка
     `x-deal.payment-proof-sheet`, та же, что в шаге; приложили — «Оплата приложена, шаг пройден»); после — файлом PDF для поставщика
     с тем, что дальше («ждём подтверждения», «оплачено»). Ждёт из `supplier-rows`: $deal, $proofOpen, $proofReq, $proofFiles,
     $proofSent, $proofState. --}}
@if ($proofOpen)
    {{-- Строкой «Платёжка — Приложить»: нажатие — выбор фото и файлов телефоном, дальше шторка «Приложить» (24.11.2026). --}}
    <x-deal.payment-proof-sheet :id="'payment-proof-card-'.$deal->id" :deal="$deal" :requirement="$proofReq" button="row sale-row signed-add">
        <x-slot:trigger>
            <span class="sale-row-label">Документ об оплате</span><span @class(['sale-row-value', 'text-accent-text' => $proofFiles->isEmpty()])>{{ $proofFiles->isEmpty() ? 'Приложить' : 'приложена, '.$proofFiles->count() }}</span><x-ui.icon name="clip" class="size-5 shrink-0 text-ink-dim"/>
        </x-slot:trigger>
    </x-deal.payment-proof-sheet>
@elseif ($proofSent)
    <x-deal.doc-row :href="'/work/deals/'.$deal->id.'/payment-proof.pdf'" title="Документ об оплате" :name="\App\Offers\Documents\PaymentProofPdf::fileName($deal)">
        @if ($proofState)<span @class(['text-open' => str_starts_with($proofState, 'оплачено')])>{{ $proofState }}</span>@endif
    </x-deal.doc-row>
@endif
