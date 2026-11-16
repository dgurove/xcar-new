{{-- Деньги сделки менеджеру по схеме оплаты (05.10.2026; с 16.11.2026 — одной карточкой «Деньги», см. ниже):
     ДКП и «страховой напрямую» — цена продажи, взаимозачёт, собственнику (или страховой), подбор XCar, «Ваша доля» зелёным;
     ПРАЙМ / Р.О.С. — цена продажи (она же счёт за ТС) и вознаграждение с выплатами. Закупочную менеджер видит только в своём
     ДКП. Счёт открывается тут же — способы оплаты под его строкой (`cabinet.deals.payment row`), отдельной страницы оплаты нет
     (19.10.2026). Договор — карточкой «Договор» (`cabinet.deals.buyer-block`). --}}
@php
    use App\Support\Money;
    use App\Billing\ChargeKind;
    use App\Billing\InvoiceState;
    use App\Offers\CommissionState;
    $live = $invoices->reject(fn ($i) => $i->state === InvoiceState::Void);
    $selection = $live->first(fn ($i) => $i->kind === ChargeKind::Selection);
    $sale = $live->first(fn ($i) => $i->kind === ChargeKind::Sale);
    $state = $deal->commissionState();
    $fee = $deal->agentFee;
    $onPayStep = $requirement && $position?->stage->isPayStep();
@endphp
@php
    $supplierState = null;
    if ($deal->paysSelection()) {
        // Платёж поставщику у Альфы (оплата до вывоза) — подтверждает поставщик: «оплачено», пока ждём — что платёжка у нас.
        $supplierState = $deal->offer?->vendor?->pay_before_pickup ? match (true) {
            $deal->prepaid_at !== null => ['оплачено', 'text-open'],
            \App\Workflow\Requirement::where('deal_id', $deal->id)->whereNotNull('done_at')->whereIn('answer->exit', \App\Offers\Documents\PaymentProofPdf::EXITS)->exists() => ['ждём подтверждения поставщика', ''],
            default => null,
        } : null;
    }
    $ownerSum = $deal->isDirect() && $deal->owner_price === null ? 'уточним' : Money::rub((int) $deal->ownerPrice());
    $sumOf = fn ($i) => Money::rub($i->state === InvoiceState::Paid ? $i->total : $i->remaining());
@endphp
{{-- «Деньги» — одна карточка, каждая сумма один раз (16.11.2026, владелец: «зачем суммы по несколько раз писать», «реально ли ты
     считаешь, что это хороший дизайн»): было «Расчёт» (закупочная, остаток) и «Платежи» (собственнику, подбор) теми же числами,
     у ПРАЙМ — цена и счёт на ту же сумму. Строки «подпись — сумма», второй строкой только факт (оплачено, ждём подтверждения,
     срок счёта); «после ответа поставщика» и прочие «когда» сняты (владелец: «не надо это всё»). Выставленный счёт — со способами
     оплаты под строкой (`cabinet.deals.payment row`); на шаге оплаты платить — в задаче шага, здесь только строки. --}}
@php $payHere = ! $onPayStep; @endphp
<div class="box deal-money-card" id="money">
    <h2 class="box-title">Деньги</h2>
    <div class="deal-money-rows">
        @if ($deal->paysSelection())
            <a href="/offers/{{ $offer->number }}" class="deal-money-row flex items-start gap-3"><span class="min-w-0 flex-1">Цена продажи</span><span class="nums shrink-0 text-lg font-semibold">{{ Money::rub($deal->amount) }}</span></a>
            @if ($deal->offset() && ! $deal->isDirect())
                <div class="deal-money-row flex items-start gap-3"><span class="min-w-0 flex-1">Взаимозачёт со страховой</span><span class="nums shrink-0 text-ink-muted">−{{ Money::rub($deal->offset()) }}</span></div>
            @endif
            {{-- За ТС — не нам: страховой или собственнику напрямую; платёжку прикладывают в задаче, подтверждает поставщик. --}}
            {{-- Кому — по схеме сделки (16.11.2026, владелец): собственнику по ДКП — «Собственнику», страховой напрямую —
                 «Поставщику» (название вендора менеджеру не показываем); подбор — юрлицо его счёта («ООО «ПРАЙМ»»), а не «Подбор XCar». --}}
            @include('cabinet.deals.payment', ['row' => true,
                'title' => $deal->schemeOf() === \App\Offers\DealScheme::Insurer ? 'Поставщику' : 'Собственнику',
                'amount' => $ownerSum, 'state' => $supplierState, 'invoice' => null])
            @include('cabinet.deals.payment', ['row' => true, 'title' => ($selection?->seller ?? \App\Billing\Seller::Prime)->config()['name'],
                'amount' => $selection ? $sumOf($selection) : Money::rub((int) $deal->ours()),
                'state' => null, 'invoice' => $selection, 'pay' => $payHere, 'payerHere' => $payHere])
            @if ($deal->commission)
                <div class="deal-money-row flex items-start gap-3"><span class="min-w-0 flex-1">Ваша доля</span><span class="profit-sum nums shrink-0">+{{ Money::rub((int) $deal->commission) }}</span></div>
            @endif
        @else
            {{-- ПРАЙМ / Р.О.С. (Совкомбанк, Carcade, часть Альфы): покупатель платит нашему юрлицу — строкой с его названием и ценой
                 (она же счёт); счёт выставлен — его оплаты и способы под ней. --}}
            @include('cabinet.deals.payment', ['row' => true, 'title' => ($sale?->seller ?? $deal->seller())->config()['name'], 'strong' => true,
                'amount' => Money::rub((int) $deal->amount), 'state' => null, 'invoice' => $sale, 'pay' => $payHere, 'payerHere' => $payHere])
            @if ($deal->commission)
                <div class="deal-money-row flex flex-col gap-2">
                    <div class="flex items-start gap-3">
                        <span class="min-w-0 flex-1"><span class="block">Ваше вознаграждение</span>@if ($state === CommissionState::Paid)<span class="block text-sm text-open">выплачено</span>@endif</span>
                        <span class="profit-sum nums shrink-0">+{{ Money::rub((int) $deal->commission) }}</span>
                    </div>
                    @if ($fee?->payments?->isNotEmpty())
                        <div class="list">
                            @foreach ($fee->payments as $p)
                                <x-money.payment :payment="$p" title="Выплачено" icon="wallet" :slip="'/account/money/invoices/'.$fee->id.'/payments/'.$p->id.'/slip'"/>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        @endif
    </div>
</div>
