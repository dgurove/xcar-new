{{-- Деньги сделки для сотрудника: расклад (цена, закупочная снимком, разница, вознаграждение, нам; по ДКП — взаимозачёт,
     собственнику, подбор), чипы —
     режим, состояние вознаграждения, «Изменить» до счёта, «Счёт»; ниже счета строками, заявка менеджера
     об оплате с решением под своим счётом, у вознаграждения к выплате — «Выплатить» в самой строке. Под неоплаченным
     счётом — его ссылка на оплату с адресом и «Отправить» (`x-billing.pay-status`): начальник открыл сделку и сразу
     видит, оплатили ли и чем поделиться. Менеджеру ничего из этого не показывается. compact — карточка строки «Сделок»:
     цена и «Нам», остальной расклад свёрнут. --}}
@props(['deal', 'compact' => false])
@php
    use App\Support\Money; use App\Billing\InvoiceState; use App\Offers\CommissionState;
    $offer = $deal->offer;
    $invoices = $deal->invoices()->with(['party', 'claims.media', 'payLinks.attempts', 'deal.offer'])->get();
    $fee = $invoices->first(fn ($i) => $i->isAgentFee());
    $issued = $invoices->reject(fn ($i) => $i->isOwed());
    $state = $deal->commissionState();
    $party = $deal->buyer ? \App\Billing\Party::forUser($deal->buyer, false) : null;
    // Этап «Проверка оплаты» сам показывает заявку менеджера с «Поступило» — здесь она только строкой, без второй пары кнопок.
    $inStep = $deal->isActive() && $offer->position()?->stage->exitsFor(\App\Workflow\Actor::Staff, $deal)->contains(fn ($x) => str_starts_with(mb_strtolower($x->label), 'оплата получена'));
    // Первый счёт на этапе оплаты предлагает сам шаг пути — здесь его второй раз не ставим.
@endphp
<x-ui.card title="Деньги" {{ $attributes }}>
    @php
        // Строки расклада: [подпись, сумма, класс суммы, главная]. В карточке строки (compact) видны главные — цена и «Нам»,
        // остальное — под «Расклад» (07.10.2026, владелец: «слишком много»), на странице сделки — всё.
        $dd = 'nums text-right';
        $rows = [];
        if ($deal->isGarage()) {
            // Гаражная: цены нет, вознаграждение назначат при продаже из гаража; важно, кто платит поставщику.
            $rows[] = ['В гараж', 'поставщику платит '.mb_strtolower($deal->garage_payer->label()), 'text-right font-medium', true];
        } else {
            $rows[] = ['Цена подтверждения', Money::rub($deal->amount), $dd.' font-medium', true];
        }
        $rows[] = ['Закупочная', $deal->cost === null ? 'не указана' : Money::rub($deal->cost), $dd, false];
        if ($deal->isGarageManager()) {
            $rows[] = ['Наша доля', $deal->share ? Money::rub($deal->share) : 'не вписана', $dd, false];
        } elseif ($deal->paysSelection()) {
            // ДКП и страховой: покупатель платит не нам, меньше закупочной — взаимозачёт со страховой.
            if ($deal->offset()) $rows[] = ['Взаимозачёт', Money::rub($deal->offset()), $dd, false];
            $rows[] = [$deal->schemeOf()->payeeLabel(), Money::rub((int) $deal->ownerPrice()), $dd, false];
            $rows[] = ['Разница', Money::rub((int) $deal->selectionBase()), $dd.($deal->selectionBase() < 0 ? ' text-danger' : ''), false];
        } elseif ($deal->margin() !== null) {
            $rows[] = ['Разница', Money::rub($deal->margin()), $dd.($deal->margin() < 0 ? ' text-danger' : ''), false];
        }
        if (! $deal->isGarage()) $rows[] = ['Вознаграждение', $deal->commission ? Money::rub($deal->commission) : 'нет', $dd, false];
        if ($deal->ours() !== null) $rows[] = ['Нам', Money::rub($deal->ours()), $dd.' text-lg font-semibold'.($deal->ours() < 0 ? ' text-danger' : ''), true];
        $rest = $compact ? array_filter($rows, fn ($r) => ! $r[3]) : [];
        $shown = $compact ? array_filter($rows, fn ($r) => $r[3]) : $rows;
    @endphp
    <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
        @foreach ($shown as [$dt, $value, $class])<dt class="text-sm text-ink-dim">{{ $dt }}</dt><dd class="{{ $class }}">{{ $value }}</dd>@endforeach
    </dl>
    @if ($rest)
        <details class="group mt-1.5">
            <summary class="flex cursor-pointer list-none items-center gap-1 text-sm text-ink-muted">Расклад<x-ui.icon name="chevron-down" class="size-4 transition-transform group-open:rotate-180"/></summary>
            <dl class="mt-1.5 grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
                @foreach ($rest as [$dt, $value, $class])<dt class="text-sm text-ink-dim">{{ $dt }}</dt><dd class="{{ $class }}">{{ $value }}</dd>@endforeach
            </dl>
        </details>
    @endif
    <div class="mt-3 flex flex-wrap items-center gap-1.5">
        <span class="tag">{{ mb_strtolower($deal->schemeOf()->label()) }}</span>
        @if ($deal->commission && $deal->isPrime())<span class="tag">{{ mb_strtolower($deal->commission_mode->label()) }}</span>@endif
        @if (! in_array($state, [CommissionState::Hidden, CommissionState::Withheld]))<x-ui.state :tone="$state->tone()">{{ mb_strtolower($state->label()) }}{{ $state === CommissionState::Payable && $fee ? ' до '.$fee->due_at->translatedFormat('j M') : '' }}{{ $state === CommissionState::Paid && $fee?->paid_at ? ' '.$fee->paid_at->translatedFormat('j M') : '' }}</x-ui.state>@endif
        @if ($deal->commission && ! $deal->withholds() && $party && ! $party->payoutReady())<x-ui.state tone="urgent">Реквизитов для выплаты нет</x-ui.state>@endif
        @if ($deal->isActive())
            @if ($deal->moneyEditable() && (! $deal->isGarage() || $deal->isGarageManager()))
                <div data-controller="sheet" class="contents">
                    <button type="button" class="chip" data-action="sheet#open">{{ $deal->isGarageManager() && ! $deal->share ? 'Вписать долю' : 'Изменить' }}</button>
                    <x-ui.sheet id="deal-money-{{ $deal->id }}" title="Деньги сделки" :open="$errors->hasAny(['commission', 'owner_price', 'share'])">
                        @if ($deal->isGarageManager())
                            {{-- Гаражная «платит менеджер»: наша доля — счёт со ссылкой встаёт сам (`SyncDealInvoices`). --}}
                            <form method="post" action="/work/deals/{{ $deal->id }}/money" class="flex flex-col gap-4">
                                @csrf @method('put')
                                <x-ui.field name="share" label="Наша доля, ₽" :value="$deal->share ? Money::nums($deal->share) : null" data-controller="digits" data-action="input->digits#format"/>
                                <x-ui.button block>Сохранить</x-ui.button>
                            </form>
                        @else
                            <x-offer.money-form :action="'/work/deals/'.$deal->id.'/money'" method="put" :amount="$deal->amount" :cost="$deal->cost" :commission="$deal->commission" :mode="$deal->commission_mode"
                                :scheme="$deal->schemeOf()" :owner-price="$deal->owner_price" submit="Сохранить"/>
                        @endif
                    </x-ui.sheet>
                </div>
            @endif
            {{-- Счета ставятся сами по схеме (`SyncDealInvoices`); здесь — только «Ещё счёт» для редкого ручного. --}}
            @if ($issued->isNotEmpty())<a href="/work/invoices/new?offer={{ $offer->number }}" class="chip">Ещё счёт</a>@endif
        @endif
    </div>
    @if ($invoices->isNotEmpty())
        {{-- Счета — одной группой строк через линию, как список в приложении: слева счёт и его состояние словом,
             справа сумма; «Выплатить» — только у вознаграждения к выплате. --}}
        <div class="list mt-4">
            @foreach ($invoices as $i)
                <div class="row">
                    <a href="/work/money/invoices/{{ $i->id }}" class="min-w-0 flex-1">
                        <span class="block">{{ $i->isOwed() ? 'Выплата менеджеру' : 'Счёт '.$i->label() }}@unless ($i->isOwed())<span class="text-ink-muted"> <x-vendor.name :party="$i->party"/></span>@endunless</span>
                        <span class="row-sub"><x-billing.light :invoice="$i"/>@if ($i->isPartial())<span class="tag nums">из {{ Money::rub($i->total) }}</span>@endif</span>
                    </a>
                    <span class="nums shrink-0 font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
                    @if ($i->isAgentFee() && $i->state === InvoiceState::Issued)
                        <span data-controller="sheet" class="contents">
                            <x-ui.button type="button" size="sm" data-action="sheet#open">Выплатить</x-ui.button>
                            <x-ui.sheet id="pay-{{ $i->id }}" :title="'Выплата '.$i->party->name"><x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'"/></x-ui.sheet>
                        </span>
                    @endif
                </div>
                @unless ($i->isOwed())<x-billing.pay-status :invoice="$i" staff/>@endunless
                @foreach ($i->claims as $p)
                    <x-money.payment :payment="$p" :slip="'/work/money/invoices/'.$i->id.'/payments/'.$p->id.'/slip'" staff>
                        @unless ($inStep)
                            <x-slot:acts>
                                <form method="post" action="/work/payments/{{ $p->id }}/confirm" class="contents" data-turbo-confirm="Поступило {{ Money::rub($p->amount) }}?">@csrf<x-ui.button size="sm">Поступило</x-ui.button></form>
                                <a href="/work/money/invoices/{{ $i->id }}" class="btn btn-s btn-quiet">Не поступила</a>
                            </x-slot:acts>
                        @endunless
                    </x-money.payment>
                @endforeach
            @endforeach
        </div>
    @endif
</x-ui.card>
