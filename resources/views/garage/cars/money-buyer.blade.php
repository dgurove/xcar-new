{{-- Деньги машины у покупателя с гаражом (26.11.2026, владелец: «он не должен нигде видеть цену закупа; цену продажи для того
     покупателя он назначает сам либо мы за него»): крупно — цена продажи (`deals.amount`), нажатие — ввести цену и вознаграждение;
     ниже его расходы, вознаграждение и «По счёту ПРАЙМ» (27.11.2026, `Deal::holderNet`), счёт. Его цены гаража (`buyer_price`,
     часто равна закупочной), закупочной, наших расходов и оплаты поставщику здесь нет. --}}
@php
    use App\Support\Money;
    $pdf = (bool) $holderInvoice?->hasPdf();
    $left = $holderInvoice ? $holderInvoice->remaining() : 0;
    $claimed = $holderInvoice && $left > 0 ? $holderInvoice->claimed() : 0;
    $mine = $car->extraSpent(\App\Garage\Payer::Manager);
    $price = $car->deal?->amount;
    // По счёту ПРАЙМ — цена минус его расходы и вознаграждение (27.11.2026, `Deal::holderNet`).
    $net = $car->deal?->holderNet();
    $commission = $car->deal?->commission;
    // Цену правят, пока расчёт не закрыт и по счёту не платили (`SetGarageSalePrice`).
    $sellable = $car->deal && $car->state !== \App\Garage\CarState::Settled && ! $car->buyerHasPaid();
@endphp
<div class="list">
    <{{ $sellable ? 'button type=button data-controller=emit data-action=emit#send data-emit-event-param=sale-price:open' : 'div' }} class="row w-full items-center gap-3 py-4 text-left">
        <span class="flex min-w-0 flex-1 flex-col items-start gap-1">
            <span class="text-sm text-ink-muted">Цена продажи</span>
            @if ($price !== null)
                <span class="nums text-[32px] leading-none font-bold">{{ Money::exact($price) }}</span>
            @else
                <span class="text-accent-text">Указать</span>
            @endif
            @if ($claimed > 0)<span class="mt-1 text-sm text-urgent">Сообщили об оплате {{ Money::exact($claimed) }}, ждём подтверждения</span>@endif
        </span>
        @if ($sellable)<x-ui.chevron/>@endif
    </{{ $sellable ? 'button' : 'div' }}>
    {{-- Из чего счёт (27.11.2026, владелец: «продаёт за 900, вложил 123 — отнимаем, 50 его комиссия — отнимаем, остальное по счёту на
         ПРАЙМ»). --}}
    @if ($price !== null && $mine > 0)<div class="row justify-between"><span>Ваши расходы</span><span class="nums">−{{ Money::rub($mine) }}</span></div>@endif
    @if ($price !== null && $commission !== null)<div class="row justify-between"><span>Ваше вознаграждение</span><span class="nums">−{{ Money::rub($commission) }}</span></div>@endif
    @if ($net !== null)<div class="row justify-between"><span>По счёту ПРАЙМ</span><span class="nums font-medium">{{ Money::rub($net) }}</span></div>@endif
    @if ($holderInvoice && $left > 0 && (float) $left !== (float) $net)
        <div class="row justify-between"><span>К оплате</span><span @class(['nums', 'text-danger' => $holderInvoice->isOverdue()])>{{ Money::rub($left) }}</span></div>
    @endif
    @if ($holderInvoice)
        <{{ $pdf ? 'a' : 'div' }} @if ($pdf) href="/account/invoices/{{ $holderInvoice->id }}/pdf" data-doc="pdf" data-doc-name="Счёт {{ $holderInvoice->label() }}" @endif class="row justify-between">
            <span class="min-w-0">
                <span class="block">Счёт {{ $holderInvoice->label() }}</span>
                <span class="row-sub">{{ $holderInvoice->state->label() }}@if ($left > 0 && $holderInvoice->due_at), до {{ \App\Support\Ago::html($holderInvoice->due_at, words: false) }}@endif</span>
            </span>
            @if ($pdf)<span class="flex shrink-0 items-center gap-1 text-accent-text"><x-ui.icon name="file" class="size-4"/>PDF</span>@endif
        </{{ $pdf ? 'a' : 'div' }}>
    @elseif ($car->deal && ($when = $car->deal->invoiceNotYet()))
        <div class="row justify-between"><span>Счёт</span><span class="text-ink-muted">{{ $when }}</span></div>
    @endif
    @if ($price === null && $mine > 0)<div class="row justify-between"><span>Ваши расходы</span><span class="nums">{{ Money::exact($mine) }}</span></div>@endif
</div>
