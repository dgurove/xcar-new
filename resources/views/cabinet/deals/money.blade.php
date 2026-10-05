{{-- Расчёт сделки по ДКП менеджеру (05.10.2026): продажа, закупочная, взаимозачёт со страховой, собственнику по ДКП
     (договор в шторке документов), нам за подбор со ссылкой на оплату и его вознаграждение — зелёным, это его прибыль.
     Закупочную менеджер видит только тут: она в его ДКП и в разговоре с собственником (исключение из «закупочной на
     xcar нет»). --}}
@php
    use App\Support\Money;
    $selection = $invoices->first(fn ($i) => $i->kind === \App\Billing\ChargeKind::Selection && $i->state !== \App\Billing\InvoiceState::Void);
    $paid = $selection?->state === \App\Billing\InvoiceState::Paid;
    $contract = \App\Offers\DealContract::for($deal)->loadMissing(['seller', 'buyer.party']);
    $pdf = ['url' => '/deals/'.$deal->id.'/dkp.pdf', 'type' => 'pdf', 'name' => 'ДКП '.$offer->titleWithYear().'.pdf', 'label' => 'ДКП'];
@endphp
<div class="box">
    <a href="/offers/{{ $offer->number }}" class="nums block text-[32px] font-bold leading-none">{{ Money::rub($deal->amount) }}</a>
    <span class="mt-1 block text-sm text-ink-muted">продажа</span>
    <div class="list mt-4">
        <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Закупочная</span><span class="nums">{{ Money::rub((int) $deal->cost) }}</span></div>
        @if ($deal->offset())
            <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Взаимозачёт со страховой</span><span class="nums text-ink-muted">−{{ Money::rub($deal->offset()) }}</span></div>
        @endif
        <x-ui.doc :doc="$pdf" class="row">
            <span class="min-w-0 flex-1"><span class="block">Собственнику по ДКП</span><span class="row-sub">@if ($contract->isReady())<span class="text-open">договор готов</span>@else договор: нет {{ implode(', ', $contract->missing()) }}@endif</span></span>
            <span class="nums font-semibold">{{ Money::rub((int) $deal->ownerPrice()) }}</span>
        </x-ui.doc>
        <a href="/account/money/deals/{{ $deal->id }}{{ $selection && ! $paid ? '?pay=1' : '' }}" class="row">
            <span class="min-w-0 flex-1">
                <span class="block">XCar за подбор</span>
                <span class="row-sub">@if ($paid)<span class="text-open">оплачено</span>@elseif ($selection)<x-billing.light :invoice="$selection"/>@else готовим счёт @endif</span>
            </span>
            <span class="nums font-semibold">{{ Money::rub($selection ? ($paid ? $selection->total : $selection->remaining()) : (int) $deal->ours()) }}</span>
        </a>
        <div class="row profit">
            <span class="min-w-0 flex-1 font-medium">Ваше вознаграждение</span>
            <span class="profit-sum nums">+{{ Money::rub((int) $deal->commission) }}</span>
        </div>
    </div>
    @if ($selection && ! $paid)<div class="mt-3"><x-billing.pay-status :invoice="$selection"/></div>@endif
</div>
