{{-- Деньги сделки для сотрудника: расклад (цена, закупочная снимком, разница, вознаграждение, нам; по ДКП — взаимозачёт,
     собственнику, подбор), чипы —
     режим, состояние вознаграждения, «Изменить» до счёта, «Счёт»; ниже счета строками, заявка менеджера
     об оплате с решением под своим счётом, у вознаграждения к выплате — «Выплатить» в самой строке. Под неоплаченным
     счётом — его ссылка на оплату с адресом и «Отправить» (`x-billing.pay-status`): начальник открыл сделку и сразу
     видит, оплатили ли и чем поделиться. Менеджеру ничего из этого не показывается. --}}
@props(['deal'])
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
    <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
        @if ($deal->isGarage())
            {{-- Гаражная: цены нет, вознаграждение назначат при продаже из гаража; важно, кто платит поставщику. --}}
            <dt class="text-sm text-ink-dim">В гараж</dt><dd class="text-right font-medium">поставщику платит {{ mb_strtolower($deal->garage_payer->label()) }}</dd>
        @else
            <dt class="text-sm text-ink-dim">Цена подтверждения</dt><dd class="nums text-right font-medium">{{ Money::rub($deal->amount) }}</dd>
        @endif
        <dt class="text-sm text-ink-dim">Закупочная</dt><dd class="nums text-right">{{ $deal->cost === null ? 'не указана' : Money::rub($deal->cost) }}</dd>
        @if ($deal->isGarageManager())
            <dt class="text-sm text-ink-dim">Наша доля</dt><dd class="nums text-right">{{ $deal->share ? Money::rub($deal->share) : 'не вписана' }}</dd>
        @elseif ($deal->paysSelection())
            {{-- ДКП и страховой: покупатель платит не нам, меньше закупочной — взаимозачёт со страховой. --}}
            @if ($deal->offset())<dt class="text-sm text-ink-dim">Взаимозачёт</dt><dd class="nums text-right">{{ Money::rub($deal->offset()) }}</dd>@endif
            <dt class="text-sm text-ink-dim">{{ $deal->schemeOf()->payeeLabel() }}</dt><dd class="nums text-right">{{ Money::rub((int) $deal->ownerPrice()) }}</dd>
            <dt class="text-sm text-ink-dim">Разница</dt><dd class="nums text-right {{ $deal->selectionBase() < 0 ? 'text-danger' : '' }}">{{ Money::rub((int) $deal->selectionBase()) }}</dd>
        @elseif ($deal->margin() !== null)<dt class="text-sm text-ink-dim">Разница</dt><dd class="nums text-right {{ $deal->margin() < 0 ? 'text-danger' : '' }}">{{ Money::rub($deal->margin()) }}</dd>@endif
        @unless ($deal->isGarage())<dt class="text-sm text-ink-dim">Вознаграждение</dt><dd class="nums text-right">{{ $deal->commission ? Money::rub($deal->commission) : 'нет' }}</dd>@endunless
        @if ($deal->ours() !== null)<dt class="text-sm text-ink-dim">Нам</dt><dd class="nums text-right text-lg font-semibold {{ $deal->ours() < 0 ? 'text-danger' : '' }}">{{ Money::rub($deal->ours()) }}</dd>@endif
    </dl>
    <div class="mt-3 flex flex-wrap items-center gap-1.5">
        <span class="tag">{{ mb_strtolower($deal->schemeOf()->label()) }}</span>
        @if ($deal->commission && $deal->isPrime())<span class="tag">{{ mb_strtolower($deal->commission_mode->label()) }}</span>@endif
        @if ($state !== CommissionState::Hidden)<x-ui.state :tone="$state->tone()">{{ mb_strtolower($state->label()) }}{{ $state === CommissionState::Payable && $fee ? ' до '.$fee->due_at->translatedFormat('j M') : '' }}{{ $state === CommissionState::Paid && $fee?->paid_at ? ' '.$fee->paid_at->translatedFormat('j M') : '' }}</x-ui.state>@endif
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
                        <span class="block">{{ $i->isOwed() ? 'Вознаграждение менеджеру' : 'Счёт '.$i->label() }}@unless ($i->isOwed())<span class="text-ink-muted"> <x-vendor.name :party="$i->party"/></span>@endunless</span>
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
