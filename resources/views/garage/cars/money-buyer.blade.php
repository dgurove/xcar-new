{{-- Деньги машины у покупателя с гаражом (20.10.2026, владелец: «покупатель нигде ни в коем случае не должен видеть
     закупочные цены»): крупно — его цена (`garage_cars.buyer_price`), ниже его счёт ПРАЙМ на неё (счёт сделки, долгом —
     вывоз его не ждёт) и его расходы. Закупочной, наших расходов, оплаты поставщику, вознаграждения и расчёта здесь нет. --}}
@php
    use App\Support\Money;
    $pdf = $holderInvoice?->getFirstMedia('file');
    $left = $holderInvoice ? $holderInvoice->remaining() : 0;
    $claimed = $holderInvoice && $left > 0 ? $holderInvoice->claimed() : 0;
    $mine = $car->extraSpent(\App\Garage\Payer::Manager);
    // Без галки «Показывать цену покупателя» (21.10.2026) — только цена продажи: ни его цены, ни счёта.
    $hide = $car->hidesBuyerPrice();
@endphp
<div class="list">
    @if ($hide)
    <div class="row flex-col items-start gap-1 py-4">
        <span class="text-sm text-ink-muted">Цена продажи</span>
        <span class="nums text-[32px] leading-none font-bold">{{ Money::exact($car->offer?->asking_price) }}</span>
    </div>
    @else
    <div class="row flex-col items-start gap-1 py-4">
        <span class="text-sm text-ink-muted">{{ $left > 0 ? 'К оплате' : 'Цена' }}</span>
        <span class="nums text-[32px] leading-none font-bold {{ $holderInvoice?->isOverdue() ? 'text-danger' : '' }}">{{ Money::exact($left > 0 ? $left : $car->buyer_price) }}</span>
        @if ($claimed > 0)<span class="mt-1 text-sm text-urgent">Сообщили об оплате {{ Money::exact($claimed) }}, ждём подтверждения</span>@endif
    </div>
    @if ($left > 0 && (float) $left !== (float) $car->buyer_price)
        <div class="row justify-between"><span>Цена</span><span class="nums">{{ Money::rub($car->buyer_price) }}</span></div>
    @endif
    @if ($holderInvoice)
        <{{ $pdf ? 'a' : 'div' }} @if ($pdf) href="/account/invoices/{{ $holderInvoice->id }}/pdf" data-doc="pdf" data-doc-name="Счёт {{ $holderInvoice->label() }}" @endif class="row justify-between">
            <span class="min-w-0">
                <span class="block">Счёт {{ $holderInvoice->label() }}</span>
                <span class="row-sub">{{ $holderInvoice->state->label() }}@if ($left > 0 && $holderInvoice->due_at), до {{ $holderInvoice->due_at->translatedFormat('j M') }}@endif</span>
            </span>
            @if ($pdf)<span class="flex shrink-0 items-center gap-1 text-accent-text"><x-ui.icon name="file" class="size-4"/>PDF</span>@endif
        </{{ $pdf ? 'a' : 'div' }}>
    @elseif ($car->deal && ($when = $car->deal->invoiceNotYet()))
        <div class="row justify-between"><span>Счёт</span><span class="text-ink-muted">{{ $when }}</span></div>
    @endif
    @endif
    @if ($mine > 0)<div class="row justify-between"><span>Ваши расходы</span><span class="nums">{{ Money::exact($mine) }}</span></div>@endif
</div>
