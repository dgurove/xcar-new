{{-- Расчёт сделки менеджеру (05.10.2026) по схеме оплаты, вознаграждение — зелёным, это его прибыль:
     — ДКП и «страховой напрямую»: продажа, закупочная, взаимозачёт, «Оплатить собственнику по ДКП» (или страховой),
       остаток, «Ваша доля» и «Оплата услуг подбора», кнопка «Оплатить». Закупочную тут менеджер видит — она в его ДКП
       (исключение из «закупочной на xcar нет»);
     — ПРАЙМ: продажа, счёт ПРАЙМ (кому, сколько, оплачен ли); закупочной нет.
     Пока поставщик не согласовал (`Deal::agreed`, 09.10.2026) — счетов нет, вместо «готовим счёт» — «после ответа поставщика».
     Только деньги: договор — в задаче или своим блоком (`cabinet.deals.contract`). Ссылка — внутри `.list`, иначе её
     стили не срабатывают и адрес в узкой колонке шёл по букве в строку.
     19.10.2026 (владелец: «менеджер путает страницу оплаты счёта и страницу сделки — объединить»): отдельного расчёта в
     «Оплатах» больше нет — счёт открывается шторкой тут же (`cabinet.deals.invoice-sheet`: строки, оплаты, PDF, ссылка),
     «К оплате» у счёта ПРАЙМ — как у подбора, выплаты вознаграждения — строками под ним. --}}
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
        // Гаражная Совкомбанка, платит покупатель: вознаграждение — с него, не от нас (27.10.2026).
        $deal->buyerPaysReward() => 'получите от покупателя',
        $deal->paysSelection() || $deal->withholds() => $live->contains(fn ($i) => $i->state === InvoiceState::Issued) ? 'оставляете себе' : 'оставили себе',
        $state === CommissionState::Paid => 'выплачено',
        $state === CommissionState::Payable => 'к выплате',
        default => 'к выплате после оплаты счёта',
    };
    // Когда встанет счёт до согласия (`Deal::invoiceNotYet`): Совкомбанк — после «Забрал», Альфа — после «Беру в работу».
    $notYet = $deal->invoiceNotYet() ?? 'после ответа поставщика';
    $fee = $deal->agentFee;
    $onPayStep = $requirement && $position?->stage->isPayStep();
@endphp
<div class="box" id="money">
    <a href="/offers/{{ $offer->number }}" class="nums block text-[32px] font-bold leading-none">{{ Money::rub($deal->amount) }}</a>
    <span class="mt-1 block text-sm text-ink-muted">продажа</span>
    @if ($deal->paysSelection())
        {{-- Деньги по порядку (06.10.2026, владелец: «+20 вознаграждения и оплатите 80 — покажется, что выйдет −60»):
             закупочная, взаимозачёт, «Оплатить собственнику по ДКП», под ними остаток — вся разница; ниже, как она
             делится: «Ваша доля» и «Оплата услуг подбора», и одна кнопка «Оплатить» (`cabinet.deals.selection-pay`). --}}
        <div class="list mt-4">
            {{-- Супер-покупателю закупочной нет (`Deal::isDirect`): только что платит собственнику. --}}
            @unless ($deal->isDirect())
            <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Закупочная</span><span class="nums">{{ Money::rub((int) $deal->cost) }}</span></div>
            @endunless
            @if ($deal->offset() && ! $deal->isDirect())
                <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Взаимозачёт со страховой</span><span class="nums text-ink-muted">−{{ Money::rub($deal->offset()) }}</span></div>
            @endif
            @php
                // Платёж поставщику у Альфы (оплата до вывоза) — подтверждает поставщик: «оплачено», пока ждём — что платёжка у нас.
                $paidSupplier = $deal->offer?->vendor?->pay_before_pickup ? match (true) {
                    $deal->prepaid_at !== null => ['оплачено', 'text-open'],
                    \App\Workflow\Requirement::where('deal_id', $deal->id)->whereNotNull('done_at')->where('answer->exit', 'Платёжное поручение приложено')->exists() => ['ждём подтверждения поставщика', ''],
                    default => null,
                } : null;
            @endphp
            <div class="row"><span class="min-w-0 flex-1"><span class="block">{{ $deal->schemeOf()->payLabel() }}</span>@if ($paidSupplier)<span @class(['row-sub', $paidSupplier[1]])>{{ $paidSupplier[0] }}</span>@endif</span><span class="nums font-semibold">@if ($deal->isDirect() && $deal->owner_price === null)уточним@else{{ Money::rub((int) $deal->ownerPrice()) }}@endif</span></div>
        </div>
        @if ($deal->selectionBase() !== null)
            <div class="mt-3 flex items-baseline justify-between gap-3 px-4"><span class="text-ink-muted">Остаток</span><span class="nums font-semibold">{{ Money::rub((int) $deal->selectionBase()) }}</span></div>
        @endif
        <div class="list mt-3">
            @if ($deal->commission)
                <div class="row profit"><span class="min-w-0 flex-1 font-medium">Ваша доля</span><span class="profit-sum nums">+{{ Money::rub((int) $deal->commission) }}</span></div>
            @endif
            <div @if ($selection) data-controller="sheet" class="contents" @else class="row" @endif>
                @if ($selection)<button type="button" class="row w-full text-left" data-action="sheet#open">@endif
                <span class="min-w-0 flex-1">
                    <span class="block">Оплата услуг подбора</span>
                    @php
                        $when = match (true) {
                            ! $selection => $deal->agreed() ? 'готовим счёт' : $notYet,
                            $selection->state === InvoiceState::Paid => 'оплачено',
                            $selection->claimed() > 0 => 'оплата ждёт подтверждения',
                            default => null,
                        };
                    @endphp
                    @if ($when)<span @class(['row-sub', 'text-open' => $selection?->state === InvoiceState::Paid])>{{ $when }}</span>
                    @elseif ($selection->due_at)<span class="row-sub"><x-billing.light :invoice="$selection"/></span>@endif
                </span>
                <span class="nums font-semibold">{{ Money::rub($selection ? ($selection->state === InvoiceState::Paid ? $selection->total : $selection->remaining()) : (int) $deal->ours()) }}</span>
                @if ($selection)<x-ui.chevron/></button>@include('cabinet.deals.invoice-sheet', ['invoice' => $selection])@endif
            </div>
        </div>
        {{-- На шаге оплаты «Оплатить» стоит в задаче (`cabinet.deals.step`) — тут его второй раз нет. --}}
        @if ($selection && ! $onPayStep)
            @include('cabinet.deals.selection-pay', ['invoice' => $selection])
        @endif
    @else
        {{-- Отменённая — ни счёта «укажите покупателя», ни вознаграждения «к выплате»: только сумма. --}}
        @unless ($deal->state === \App\Offers\DealState::Cancelled && ! $sale)
        <div class="list mt-4">
            <div @if ($sale) data-controller="sheet" class="contents" @else class="contents" @endif>
            @if ($sale)<button type="button" class="row w-full text-left" data-action="sheet#open">@else<a href="#dkp-buyer" class="row">@endif
                <span class="min-w-0 flex-1">
                    <span class="block">{{ 'Счёт '.$deal->seller()->short().($sale ? ' '.$sale->label() : '') }}</span>
                    <span class="row-sub">@if (! $sale && ! $deal->agreed()){{ $notYet }}@elseif (! $sale)<span class="text-urgent">{{ $deal->invoiceGap() === 'passport' ? 'нужен паспорт покупателя' : 'укажите покупателя' }}</span>@elseif ($sale->state === InvoiceState::Paid)<span class="text-open">оплачен</span>@else<x-billing.light :invoice="$sale"/> платит {{ $sale->party?->name }}@endif</span>
                </span>
                @if ($sale)<span class="nums font-semibold">{{ Money::rub($sale->state === InvoiceState::Paid ? $sale->total : $sale->remaining()) }}</span><x-ui.chevron/>@endif
            @if ($sale)</button>@include('cabinet.deals.invoice-sheet', ['invoice' => $sale])@else</a>@endif
            </div>
            @if ($deal->commission)
                <div class="row profit">
                    <span class="min-w-0 flex-1"><span class="block font-medium">Ваше вознаграждение</span><span class="row-sub">{{ $feeWord }}</span></span>
                    <span class="profit-sum nums">+{{ Money::rub((int) $deal->commission) }}</span>
                </div>
                @foreach ($fee?->payments ?? [] as $p)
                    <x-money.payment :payment="$p" title="Выплачено" icon="wallet" :slip="'/account/money/invoices/'.$fee->id.'/payments/'.$p->id.'/slip'"/>
                @endforeach
            @endif
        </div>
        {{-- Оплатить счёт ПРАЙМ — той же кнопкой, что подбор; на шаге оплаты она в задаче. --}}
        @if ($sale && ! $onPayStep)
            @include('cabinet.deals.selection-pay', ['invoice' => $sale])
        @endif
        @endunless
    @endif
</div>
