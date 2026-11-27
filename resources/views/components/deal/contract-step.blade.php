{{-- Договор в шаге «Подписанный договор» «Хода сделки» (27.11.2026, владелец: «есть только кнопка „Договор получен“, а самих кнопок
     договора нет; там должен сам договор и кнопки: скорректировать, отправить, покупатель, если не заполнен — то, что в блоке
     „Договор“»). Те же куски, что в «Деньгах» (`x-deal.money`): покупатель (`x-deal.contract` — данные, «Заполнить»), подпись
     «Договор» со словом (`DealContract::word`), собственник по договору комиссии, чего не хватает и строка договора
     (`x-deal.contract-line`: «Заполнить…», «Отправить менеджеру», «Отозвать», «Приложить подписанный»). --}}
@props(['deal'])
@php
    $canEdit = (bool) auth()->user()?->canEditDeals();
    $signed = \App\Offers\Documents\SignedContractPdf::files($deal)->isNotEmpty();
    $contract = \App\Offers\DealContract::for($deal);
    $ready = $contract->sendable();
    $word = \App\Offers\DealContract::word($deal, $contract, $signed);
@endphp
<div {{ $attributes->merge(['class' => 'contract-step']) }}>
    @unless ($signed)<div class="mb-3"><x-deal.contract :deal="$deal" bare :doc-row="false" capped/></div>@endunless
    <x-deal.doc-cap label="Договор" :state="$word[0] ?? null" :tone="$word[1] ?? 'muted'"/>
    <div class="list">
        @if (\App\Offers\Actions\SetDealOwner::allows($deal))<x-deal.owner :deal="$deal" :editable="$canEdit"/>@endif
        {{-- Чего не хватает, говорит строка-заготовка — отдельными строками только без неё (как в «Деньгах»). --}}
        @unless ($canEdit && $deal->isActive() && ! $ready && ! $signed)<x-deal.contract-gaps :deal="$deal" :price-row="true"/>@endunless
        <x-deal.contract-line :deal="$deal" :can-edit="$canEdit" :with-contract="true" :signed="$signed" :ready="$ready"/>
    </div>
</div>
