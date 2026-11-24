{{-- Деньги машины у покупателя с гаражом (20.10.2026, владелец: «покупатель нигде ни в коем случае не должен видеть
     закупочные цены»): крупно — его цена (`garage_cars.buyer_price`), ниже его счёт ПРАЙМ на неё (счёт сделки, долгом —
     вывоз его не ждёт) и его расходы. Закупочной, наших расходов, оплаты поставщику, вознаграждения и расчёта здесь нет. --}}
@php
    use App\Support\Money;
    $pdf = (bool) $holderInvoice?->hasPdf();
    $left = $holderInvoice ? $holderInvoice->remaining() : 0;
    $claimed = $holderInvoice && $left > 0 ? $holderInvoice->claimed() : 0;
    $mine = $car->extraSpent(\App\Garage\Payer::Manager);
    // Без галки «Показывать цену покупателя» (21.10.2026) — только цена продажи: ни его цены, ни счёта.
    $hide = $car->hidesBuyerPrice();
@endphp
<div class="list">
    @if ($hide)
    {{-- Цена продажи — его (24.11.2026, «как в сделке»): вписал — она, нет — наша подсказка; нажатие — ввести. Вознаграждение — ниже. --}}
    @php $sellable = $car->deal && $car->state !== \App\Garage\CarState::Settled; @endphp
    <{{ $sellable ? 'button type=button data-controller=emit data-action=emit#send data-emit-event-param=sale-price:open' : 'div' }} class="row w-full items-center gap-3 py-4 text-left">
        <span class="flex min-w-0 flex-1 flex-col items-start gap-1">
            <span class="text-sm text-ink-muted">Цена продажи</span>
            <span class="nums text-[32px] leading-none font-bold">{{ Money::exact($car->deal?->amount ?? $car->offer?->asking_price) }}</span>
        </span>
        @if ($sellable)<x-ui.chevron/>@endif
    </{{ $sellable ? 'button' : 'div' }}>
    @if ($car->deal?->amount !== null)
        <div class="row justify-between"><span>Ваше вознаграждение</span><span class="nums text-open">{{ Money::rub((int) $car->deal->amount - (int) $car->buyer_price) }}</span></div>
    @endif
    @else
    <div class="row flex-col items-start gap-1 py-4">
        <span class="text-sm text-ink-muted">{{ $left > 0 ? 'К оплате' : 'Цена' }}</span>
        <span class="nums text-[32px] leading-none font-bold {{ $holderInvoice?->isOverdue() ? 'text-danger' : '' }}">{{ Money::exact($left > 0 ? $left : $car->buyer_price) }}</span>
        @if ($claimed > 0)<span class="mt-1 text-sm text-urgent">Сообщили об оплате {{ Money::exact($claimed) }}, ждём подтверждения</span>@endif
    </div>
    @if ($car->deal && $car->state !== \App\Garage\CarState::Settled)
        {{-- Цена продажи и вознаграждение (24.11.2026): как в сделке — сколько платит клиент, разница с ценой — его. --}}
        <button type="button" class="row justify-between w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="sale-price:open">
            <span>Цена продажи</span>
            <span class="flex items-center gap-2">@if ($car->deal->amount !== null)<span class="nums">{{ Money::rub($car->deal->amount) }}</span>@else<span class="text-accent-text">Указать</span>@endif<x-ui.chevron/></span>
        </button>
    @elseif ($car->deal?->amount !== null)
        <div class="row justify-between"><span>Цена продажи</span><span class="nums">{{ Money::rub($car->deal->amount) }}</span></div>
    @endif
    @if ($car->deal?->amount !== null)
        <div class="row justify-between"><span>Ваше вознаграждение</span><span class="nums text-open">{{ Money::rub((int) $car->deal->amount - (int) $car->buyer_price) }}</span></div>
    @endif
    @if ($left > 0 && (float) $left !== (float) $car->buyer_price)
        <div class="row justify-between"><span>Цена</span><span class="nums">{{ Money::rub($car->buyer_price) }}</span></div>
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
    @endif
    @if ($mine > 0)<div class="row justify-between"><span>Ваши расходы</span><span class="nums">{{ Money::exact($mine) }}</span></div>@endif
</div>
