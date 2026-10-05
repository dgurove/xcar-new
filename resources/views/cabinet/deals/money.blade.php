{{-- Расчёт сделки менеджеру (05.10.2026) по схеме оплаты, вознаграждение — зелёным, это его прибыль:
     — ДКП и «страховой напрямую»: продажа, закупочная, взаимозачёт со страховой, собственнику по ДКП или страховой, XCar за подбор с оплатой. Закупочную тут менеджер видит — она в его ДКП (исключение из «закупочной
       на xcar нет»);
     — ПРАЙМ: продажа, счёт ПРАЙМ (кому, сколько, оплачен ли); закупочной нет.
     Только деньги: договор — в задаче или своим блоком (`cabinet.deals.contract`). Ссылка — внутри `.list`, иначе её
     стили не срабатывают и адрес в узкой колонке шёл по букве в строку. --}}
@php
    use App\Support\Money;
    use App\Billing\ChargeKind;
    use App\Billing\InvoiceState;
    use App\Offers\CommissionState;
    $live = $invoices->reject(fn ($i) => $i->state === InvoiceState::Void);
    $selection = $live->first(fn ($i) => $i->kind === ChargeKind::Selection);
    $sale = $live->first(fn ($i) => $i->kind === ChargeKind::Sale);
    $state = $deal->commissionState();
    $feeWord = match (true) {
        $deal->paysSelection() || $deal->withholds() => 'удерживаете из оплаты',
        $state === CommissionState::Paid => 'выплачено',
        $state === CommissionState::Payable => 'к выплате',
        default => 'к выплате после оплаты счёта',
    };
    $pay = fn ($i) => '/account/money/deals/'.$deal->id.($i && $i->state === InvoiceState::Issued ? '?pay=1' : '');
@endphp
<div class="box">
    <a href="/offers/{{ $offer->number }}" class="nums block text-[32px] font-bold leading-none">{{ Money::rub($deal->amount) }}</a>
    <span class="mt-1 block text-sm text-ink-muted">продажа</span>
    <div class="list mt-4">
        @if ($deal->paysSelection())
            <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Закупочная</span><span class="nums">{{ Money::rub((int) $deal->cost) }}</span></div>
            @if ($deal->offset())
                <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Взаимозачёт со страховой</span><span class="nums text-ink-muted">−{{ Money::rub($deal->offset()) }}</span></div>
            @endif
            <div class="row"><span class="min-w-0 flex-1">{{ $deal->schemeOf()->payeeLabel() }}</span><span class="nums font-semibold">{{ Money::rub((int) $deal->ownerPrice()) }}</span></div>
            <a href="{{ $pay($selection) }}" class="row">
                <span class="min-w-0 flex-1">
                    <span class="block">XCar за подбор</span>
                    <span class="row-sub">@if ($selection?->state === InvoiceState::Paid)<span class="text-open">оплачено</span>@elseif ($selection)<x-billing.light :invoice="$selection"/>@else готовим счёт @endif</span>
                </span>
                <span class="nums font-semibold">{{ Money::rub($selection ? ($selection->state === InvoiceState::Paid ? $selection->total : $selection->remaining()) : (int) $deal->ours()) }}</span>
            </a>
        @else
            <a href="{{ $sale ? $pay($sale) : '#dkp-buyer' }}" class="row">
                <span class="min-w-0 flex-1">
                    <span class="block">{{ $sale ? 'Счёт ПРАЙМ '.$sale->label() : 'Счёт ПРАЙМ' }}</span>
                    <span class="row-sub">@if (! $sale)<span class="text-urgent">укажите покупателя</span>@elseif ($sale->state === InvoiceState::Paid)<span class="text-open">оплачен</span>@else<x-billing.light :invoice="$sale"/> платит {{ $sale->party?->name }}@endif</span>
                </span>
                @if ($sale)<span class="nums font-semibold">{{ Money::rub($sale->state === InvoiceState::Paid ? $sale->total : $sale->remaining()) }}</span>@endif
            </a>
        @endif
        @if ($deal->commission)
            <div class="row profit">
                <span class="min-w-0 flex-1"><span class="block font-medium">Ваше вознаграждение</span><span class="row-sub">{{ $feeWord }}</span></span>
                <span class="profit-sum nums">+{{ Money::rub((int) $deal->commission) }}</span>
            </div>
        @endif
    </div>
    {{-- На шаге оплаты ссылка стоит в задаче вместе с «Оплатить» — второй раз её здесь нет. --}}
    @if ($selection && $selection->state === InvoiceState::Issued && ! ($requirement && $position?->stage->isPayStep()))<div class="list mt-3"><x-billing.pay-status :invoice="$selection"/></div>@endif
</div>
