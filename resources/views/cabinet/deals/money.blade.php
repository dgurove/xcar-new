{{-- Расчёт сделки менеджеру (05.10.2026) по схеме оплаты, вознаграждение — зелёным, это его прибыль:
     — ДКП и «страховой напрямую»: продажа, закупочная, взаимозачёт со страховой, «Оплатить собственнику по ДКП» (или
       страховой), вознаграждение, ниже — «Оплатите XCar за подбор» со ссылкой (`cabinet.deals.selection-pay`). Закупочную
       тут менеджер видит — она в его ДКП (исключение из «закупочной на xcar нет»);
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
        $deal->paysSelection() || $deal->withholds() => $live->contains(fn ($i) => $i->state === InvoiceState::Issued) ? 'оставляете себе' : 'оставили себе',
        $state === CommissionState::Paid => 'выплачено',
        $state === CommissionState::Payable => 'к выплате',
        default => 'к выплате после оплаты счёта',
    };
    $pay = fn ($i) => '/account/money/deals/'.$deal->id.($i && $i->state === InvoiceState::Issued ? '?pay=1' : '');
@endphp
<div class="box">
    <a href="/offers/{{ $offer->number }}" class="nums block text-[32px] font-bold leading-none">{{ Money::rub($deal->amount) }}</a>
    <span class="mt-1 block text-sm text-ink-muted">продажа</span>
    @if ($deal->paysSelection())
        {{-- Что куда платить — по порядку и глаголом (06.10.2026, владелец): закупочная, взаимозачёт, «Оплатить
             собственнику по ДКП», его вознаграждение; ниже своей группой — «Оплатите XCar за подбор» со ссылкой. --}}
        <div class="list mt-4">
            <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Закупочная</span><span class="nums">{{ Money::rub((int) $deal->cost) }}</span></div>
            @if ($deal->offset())
                <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Взаимозачёт со страховой</span><span class="nums text-ink-muted">−{{ Money::rub($deal->offset()) }}</span></div>
            @endif
            <div class="row"><span class="min-w-0 flex-1">{{ $deal->schemeOf()->payLabel() }}</span><span class="nums font-semibold">{{ Money::rub((int) $deal->ownerPrice()) }}</span></div>
            @if ($deal->commission)
                <div class="row profit">
                    <span class="min-w-0 flex-1"><span class="block font-medium">Ваше вознаграждение</span><span class="row-sub">{{ $feeWord }}</span></span>
                    <span class="profit-sum nums">+{{ Money::rub((int) $deal->commission) }}</span>
                </div>
            @endif
        </div>
        {{-- На шаге оплаты оплата стоит в задаче (`cabinet.deals.step`) — тут её второй раз нет. --}}
        @if ($selection && ! ($requirement && $position?->stage->isPayStep()))
            @include('cabinet.deals.selection-pay', ['invoice' => $selection])
        @elseif (! $selection)
            <div class="list mt-4"><div class="row"><span class="min-w-0 flex-1"><span class="block">XCar за подбор</span><span class="row-sub">готовим счёт</span></span><span class="nums font-semibold">{{ Money::rub((int) $deal->ours()) }}</span></div></div>
        @endif
    @else
        <div class="list mt-4">
            <a href="{{ $sale ? $pay($sale) : '#dkp-buyer' }}" class="row">
                <span class="min-w-0 flex-1">
                    <span class="block">{{ $sale ? 'Счёт ПРАЙМ '.$sale->label() : 'Счёт ПРАЙМ' }}</span>
                    <span class="row-sub">@if (! $sale)<span class="text-urgent">укажите покупателя</span>@elseif ($sale->state === InvoiceState::Paid)<span class="text-open">оплачен</span>@else<x-billing.light :invoice="$sale"/> платит {{ $sale->party?->name }}@endif</span>
                </span>
                @if ($sale)<span class="nums font-semibold">{{ Money::rub($sale->state === InvoiceState::Paid ? $sale->total : $sale->remaining()) }}</span>@endif
            </a>
            @if ($deal->commission)
                <div class="row profit">
                    <span class="min-w-0 flex-1"><span class="block font-medium">Ваше вознаграждение</span><span class="row-sub">{{ $feeWord }}</span></span>
                    <span class="profit-sum nums">+{{ Money::rub((int) $deal->commission) }}</span>
                </div>
            @endif
        </div>
    @endif
</div>
