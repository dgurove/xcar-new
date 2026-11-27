{{-- Договор одной строкой (вынесено из `x-deal.money` 27.11.2026 — та же строка в шаге договора «Хода сделки», `x-deal.contract-step`):
     подписан — он, иначе готовый со скрепкой «приложить подписанный»; не готов — «Подписанный договор — Приложить» (подписали без нас). --}}
@props(['deal', 'canEdit' => null, 'withContract' => null, 'signed' => null, 'ready' => null])
@php
    $canEdit ??= (bool) auth()->user()?->canEditDeals();
    $withContract ??= $deal->hasContract() && ! $deal->isAuction();
    $signed ??= \App\Offers\Documents\SignedContractPdf::files($deal)->isNotEmpty();
    $ready ??= $withContract && \App\Offers\DealContract::for($deal)->sendable();
@endphp
@if ($withContract || $canEdit)
    @if ($signed && $canEdit)
        <x-deal.signed-contract :deal="$deal" :base="'/work/deals/'.$deal->id" bare doc-title="Договор"/>
    @elseif ($ready)
        <x-deal.contract-doc :deal="$deal" :attach="$canEdit"/>
    @elseif ($withContract && $canEdit && $deal->isActive())
        {{-- Не готов — строкой, как готовый: нажатие — чего не хватает, с полями (18.11.2026, `x-deal.contract-gaps-sheet`). --}}
        <x-deal.contract-gaps-sheet :deal="$deal" :attach="true"/>
    @elseif ($canEdit)
        <x-deal.signed-contract :deal="$deal" :base="'/work/deals/'.$deal->id" bare/>
    @endif
@endif
