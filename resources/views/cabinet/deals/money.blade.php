{{-- Деньги сделки менеджеру по схеме оплаты (05.10.2026; с 16.11.2026 — одной карточкой без заголовка, на шаге оплаты — в нём):
     ДКП и «страховой напрямую» — цена продажи, взаимозачёт, собственнику (или страховой), подбор XCar, «Ваша доля» зелёным;
     ПРАЙМ / Р.О.С. — счёт за ТС (цена минус вознаграждение) и вознаграждение числом (выплат нет, 18.11.2026). Закупочную менеджер видит только в своём
     ДКП. Счёт открывается тут же — способы оплаты под его строкой (`cabinet.deals.payment row`), отдельной страницы оплаты нет
     (19.10.2026). Договор — карточкой «Договор» (`cabinet.deals.buyer-block`). --}}
@php
    use App\Support\Money;
    use App\Billing\ChargeKind;
    use App\Billing\InvoiceState;
    $live = $invoices->reject(fn ($i) => $i->state === InvoiceState::Void);
    $selection = $live->first(fn ($i) => $i->kind === ChargeKind::Selection);
    $sale = $live->first(fn ($i) => $i->kind === ChargeKind::Sale);
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
{{-- Шаг оплаты (16.11.2026, владелец: «встрой оплату в блок деньги, убери слово „Деньги“, объедини два блока в один»): строки —
     внутри карточки шага «Оплата» (`embedded`, `cabinet.deals.step`), своей карточки тогда нет; платить — здесь же. На остальных
     шагах — своей карточкой без заголовка. --}}
@php
    $embedded ??= false;
    $payHere = $embedded || ! $onPayStep;
    // «Страховой напрямую» / ДКП на оплате: чек — одной «Приложить чек» под строками (`cabinet.deals.receipt`), у ПРАЙМ своей нет.
    $oneReceipt = $embedded && $deal->paysSelection();
    $supplierFiles = [];
    if ($embedded && $deal->paysSelection()) {
        foreach (\App\Offers\SupplierInvoice::shown($offer) as $f) {
            $supplierFiles[] = ['Счёт поставщика', $f, 'от '.$f->created_at->timezone('Europe/Moscow')->format('d.m')];
        }
        foreach ($deal->sentPaymentProof()?->getMedia('files') ?? [] as $f) {
            $supplierFiles[] = ['Платёжка', $f, \App\Support\Ago::at($f->created_at, true, words: false)];
        }
    }
@endphp
@unless ($embedded)<div class="box deal-money-card" id="money">@endunless
    <div class="deal-money-rows{{ $embedded ? ' mt-4' : '' }}">
        @if ($deal->paysSelection())
            <a href="/offers/{{ $offer->number }}" class="deal-money-row deal-money-price flex items-start gap-3"><span class="min-w-0 flex-1">Цена продажи</span><span class="nums shrink-0 text-lg font-semibold">{{ Money::rub($deal->amount) }}</span></a>
            @if ($deal->offset() && ! $deal->isDirect())
                <div class="deal-money-row flex items-start gap-3"><span class="min-w-0 flex-1">Взаимозачёт со страховой</span><span class="nums shrink-0 text-ink-muted">−{{ Money::rub($deal->offset()) }}</span></div>
            @endif
            {{-- За ТС — не нам: страховой или собственнику напрямую; платёжку прикладывают в задаче, подтверждает поставщик. --}}
            {{-- Кому — по схеме сделки (16.11.2026, владелец): собственнику по ДКП — «Собственнику», страховой напрямую —
                 «Поставщику» (название вендора менеджеру не показываем); подбор — юрлицо его счёта («ООО «ПРАЙМ»»), а не «Подбор XCar». --}}
            @include('cabinet.deals.payment', ['row' => true,
                'title' => $deal->schemeOf() === \App\Offers\DealScheme::Insurer ? 'Поставщику' : 'Собственнику',
                'amount' => $ownerSum, 'state' => $supplierState, 'invoice' => null, 'buyerPays' => $deal->isActive(), 'block' => true, 'files' => $supplierFiles,
                'pickBuyer' => $deal->isLive() && ! $deal->hasContract()])
            @include('cabinet.deals.payment', ['row' => true, 'title' => ($selection?->seller ?? \App\Billing\Seller::Prime)->config()['name'],
                'amount' => $selection ? $sumOf($selection) : Money::rub((int) $deal->ours()),
                'state' => null, 'invoice' => $selection, 'pay' => $payHere, 'payerHere' => $payHere, 'block' => true, 'noSlip' => $oneReceipt])
            @include('cabinet.deals.money-reward')
        @else
            {{-- ПРАЙМ / Р.О.С. (Совкомбанк, Carcade, часть Альфы): покупатель платит нашему юрлицу — строкой с его названием и ценой
                 (она же счёт); счёт выставлен — его оплаты и способы под ней. --}}
            {{-- «Квитанцией» (18.11.2026, владелец выбрал вариант А): цена продажи → счёт нашего юрлица → вознаграждение → покупатель. --}}
            @if ($deal->amount !== null && ! $deal->isGarage())
                <div class="deal-money-row deal-money-price flex items-start gap-3"><span class="min-w-0 flex-1">Цена продажи</span><span class="nums shrink-0 text-lg font-semibold">{{ Money::rub($deal->amount) }}</span></div>
            @endif
            {{-- Покупатель уже есть на странице — в карточке «Договор и счёт» или в шаге (`buyerPickInMoney` — нет) — второй раз не пишем
                 (18.11.2026: «Михаил Шмелёв» стоял дважды). --}}
            @if ($deal->buyer_id === auth()->id() && $deal->isActive() && ! $deal->isDirect() && $deal->schemeOf()->paysCar() && ($buyerPickInMoney ?? true))
                <div class="deal-money-buyer pb-2"><x-deal.payer :deal="$deal" :action="'/deals/'.$deal->id.'/payer'" :id="'deal-payer-'.$deal->id.'-money'" capped/></div>
            @endif
            @include('cabinet.deals.payment', ['row' => true, 'title' => ($sale?->seller ?? $deal->seller())->config()['name'], 'strong' => ! ($deal->amount !== null && ! $deal->isGarage()),
                // Сумма — та, что платить: цена минус вознаграждение и наличные мимо счёта (как счёт и договор, `DealContract::priceFor`).
                'amount' => Money::rub((int) ($sale ? $sale->total : \App\Offers\DealContract::priceFor($deal))), 'state' => null, 'invoice' => $sale, 'pay' => $payHere, 'payerHere' => $payHere, 'payerLast' => true, 'block' => true])
            @include('cabinet.deals.money-reward')
        @endif
    </div>
@unless ($embedded)</div>@endunless
