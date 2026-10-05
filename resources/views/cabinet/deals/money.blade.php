{{-- Расчёт сделки менеджеру (05.10.2026) по схеме оплаты, вознаграждение — зелёным, это его прибыль:
     — ДКП и «страховой напрямую»: продажа, закупочная, взаимозачёт, «Оплатить собственнику по ДКП» (или страховой),
       остаток, «Ваша доля» и «Оплата услуг подбора», кнопка «Оплатить». Закупочную тут менеджер видит — она в его ДКП
       (исключение из «закупочной на xcar нет»);
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
        {{-- Деньги по порядку (06.10.2026, владелец: «+20 вознаграждения и оплатите 80 — покажется, что выйдет −60»):
             закупочная, взаимозачёт, «Оплатить собственнику по ДКП», под ними остаток — вся разница; ниже, как она
             делится: «Ваша доля» и «Оплата услуг подбора», и одна кнопка «Оплатить» (`cabinet.deals.selection-pay`). --}}
        <div class="list mt-4">
            <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Закупочная</span><span class="nums">{{ Money::rub((int) $deal->cost) }}</span></div>
            @if ($deal->offset())
                <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Взаимозачёт со страховой</span><span class="nums text-ink-muted">−{{ Money::rub($deal->offset()) }}</span></div>
            @endif
            <div class="row"><span class="min-w-0 flex-1">{{ $deal->schemeOf()->payLabel() }}</span><span class="nums font-semibold">{{ Money::rub((int) $deal->ownerPrice()) }}</span></div>
        </div>
        @if ($deal->selectionBase() !== null)
            <div class="mt-3 flex items-baseline justify-between gap-3 px-4"><span class="text-ink-muted">Остаток</span><span class="nums font-semibold">{{ Money::rub((int) $deal->selectionBase()) }}</span></div>
        @endif
        <div class="list mt-3">
            @if ($deal->commission)
                <div class="row profit"><span class="min-w-0 flex-1 font-medium">Ваша доля</span><span class="profit-sum nums">+{{ Money::rub((int) $deal->commission) }}</span></div>
            @endif
            <div class="row">
                <span class="min-w-0 flex-1">
                    <span class="block">Оплата услуг подбора</span>
                    @php
                        $when = match (true) {
                            ! $selection => 'готовим счёт',
                            $selection->state === InvoiceState::Paid => 'оплачено',
                            $selection->claimed() > 0 => 'оплата ждёт подтверждения',
                            default => null,
                        };
                    @endphp
                    @if ($when)<span @class(['row-sub', 'text-open' => $selection?->state === InvoiceState::Paid])>{{ $when }}</span>
                    @elseif ($selection->due_at)<span class="row-sub"><x-billing.light :invoice="$selection"/></span>@endif
                </span>
                <span class="nums font-semibold">{{ Money::rub($selection ? ($selection->state === InvoiceState::Paid ? $selection->total : $selection->remaining()) : (int) $deal->ours()) }}</span>
            </div>
        </div>
        {{-- На шаге оплаты «Оплатить» стоит в задаче (`cabinet.deals.step`) — тут его второй раз нет. --}}
        @if ($selection && ! ($requirement && $position?->stage->isPayStep()))
            @include('cabinet.deals.selection-pay', ['invoice' => $selection])
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
