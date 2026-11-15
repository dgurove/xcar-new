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
{{-- 15.11.2026 (владелец: «всем кажется, что это три разных счёта… на нормальных платформах вообще не так делают блок оплаты»):
     «Расчёт» — откуда деньги, без кнопок; «Платежи» — кому и сколько заплатить, каждый платёж одной плашкой
     (`cabinet.deals.payment`: кому, за что, сумма, состояние, файл счёта, одна «Оплатить»). На шаге оплаты платежи с кнопкой —
     в задаче шага, здесь только расчёт. --}}
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
    // Счёта ещё нет — словом, почему. Оплачен или ждёт подтверждения — это говорят строки оплат в самой квитанции.
    $invoiceState = fn ($i, string $none) => $i ? null : [$none, ''];
    $sumOf = fn ($i) => Money::rub($i->state === InvoiceState::Paid ? $i->total : $i->remaining());
    $cancelledEmpty = $deal->state === \App\Offers\DealState::Cancelled && ! $sale && ! $selection;
@endphp
<div class="flex flex-col gap-4">
<div class="box" id="money">
    <h2 class="box-title mb-3">Расчёт</h2>
    {{-- Цена — строкой, числом окна (16.11.2026, вариант А: пять крупных сумм подряд — 32 и 24 px — «всё скачет»). --}}
    <a href="/offers/{{ $offer->number }}" class="flex items-baseline justify-between gap-3"><span class="text-ink-muted">Цена продажи</span><span class="nums text-lg font-semibold">{{ Money::rub($deal->amount) }}</span></a>
    @if ($deal->paysSelection())
        {{-- Деньги по порядку (06.10.2026, владелец: «+20 вознаграждения и оплатите 80 — покажется, что выйдет −60»): закупочная,
             взаимозачёт, остаток — вся разница; ниже, как она делится: «Ваша доля» и подбор XCar. Платить — в «Платежах». --}}
        <div class="list mt-4">
            {{-- Супер-покупателю закупочной нет (`Deal::isDirect`): только что платит собственнику. --}}
            @unless ($deal->isDirect())
                <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Закупочная</span><span class="nums">{{ Money::rub((int) $deal->cost) }}</span></div>
            @endunless
            @if ($deal->offset() && ! $deal->isDirect())
                <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Взаимозачёт со страховой</span><span class="nums text-ink-muted">−{{ Money::rub($deal->offset()) }}</span></div>
            @endif
            {{-- Страховой / собственнику — строкой, только когда это не та же закупочная (взаимозачёт, супер-покупатель). --}}
            @if ($deal->isDirect() || $deal->offset())
                <div class="row"><span class="min-w-0 flex-1 text-ink-muted">{{ $deal->schemeOf()->payeeLabel() }}</span><span class="nums">{{ $ownerSum }}</span></div>
            @endif
            @if ($deal->selectionBase() !== null)
                <div class="row"><span class="min-w-0 flex-1 font-medium">Остаток</span><span class="nums font-semibold">{{ Money::rub((int) $deal->selectionBase()) }}</span></div>
            @endif
            @if ($deal->commission)
                <div class="row profit"><span class="min-w-0 flex-1 font-medium">Ваша доля</span><span class="profit-sum nums">+{{ Money::rub((int) $deal->commission) }}</span></div>
                {{-- Без доли остаток и есть подбор — тем же числом второй раз не пишем. --}}
                <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Подбор XCar</span><span class="nums">{{ Money::rub($selection ? $selection->total : (int) $deal->ours()) }}</span></div>
            @endif
        </div>
    @elseif ($deal->commission)
        <div class="list mt-4">
            <div class="row profit">
                <span class="min-w-0 flex-1"><span class="block font-medium">Вознаграждение</span><span class="row-sub">{{ $feeWord }}</span></span>
                <span class="profit-sum nums">+{{ Money::rub((int) $deal->commission) }}</span>
            </div>
            @foreach ($fee?->payments ?? [] as $p)
                <x-money.payment :payment="$p" title="Выплачено" icon="wallet" :slip="'/account/money/invoices/'.$fee->id.'/payments/'.$p->id.'/slip'"/>
            @endforeach
        </div>
    @endif
</div>
@unless ($onPayStep || $cancelledEmpty)
    <section class="flex min-w-0 flex-col gap-3" aria-label="Платежи">
        <h2 class="list-head">Платежи</h2>
        @if ($deal->paysSelection())
            {{-- За ТС — не нам: страховой или собственнику напрямую; платёжку прикладывают в задаче, подтверждает поставщик. --}}
            @include('cabinet.deals.payment', [
                'title' => $deal->schemeOf() === \App\Offers\DealScheme::Insurer ? ($offer->vendor?->name ?? 'Страховой') : 'Собственнику',
                'amount' => $ownerSum, 'state' => $supplierState, 'invoice' => null])
            @include('cabinet.deals.payment', [
                'title' => 'Подбор',
                'amount' => $selection ? $sumOf($selection) : Money::rub((int) $deal->ours()),
                'state' => $invoiceState($selection, $deal->agreed() ? 'готовим счёт' : $notYet), 'invoice' => $selection, 'pay' => true])
        @else
            {{-- Счёт за автомобиль — ПРАЙМ или Р.О.С.; нет покупателя — ведёт к нему. --}}
            @include('cabinet.deals.payment', [
                'amount' => $sale ? $sumOf($sale) : Money::rub((int) $deal->amount),
                'state' => $sale ? $invoiceState($sale, '') : (! $deal->agreed() ? [$notYet, ''] : [$deal->invoiceGap() === 'passport' ? 'нужны данные покупателя' : 'укажите покупателя', 'text-urgent']),
                'invoice' => $sale, 'pay' => true, 'href' => $sale || ! $deal->agreed() ? null : '#dkp-buyer'])
        @endif
    </section>
@endunless
</div>
