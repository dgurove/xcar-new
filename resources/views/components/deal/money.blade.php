{{-- Деньги сделки для сотрудника: расклад (цена, закупочная снимком, разница, вознаграждение, нам; по ДКП — взаимозачёт,
     собственнику, подбор), чипы —
     режим, состояние вознаграждения, «Изменить» до счёта, «Счёт»; ниже счета строками, заявка менеджера
     об оплате с решением под своим счётом, у вознаграждения к выплате — «Выплатить» в самой строке. Под неоплаченным
     счётом — его ссылка на оплату строкой группы «Ссылки на оплату» с «Скопировать» (`x-billing.link-row`): начальник открыл
     сделку и сразу видит, оплатили ли и чем поделиться. Менеджеру ничего из этого не показывается. compact — карточка строки «Сделок»:
     цена и «Нам», остальной расклад свёрнут. 07.10.2026: кнопки шторок .sheet-foot. --}}
@props(['deal', 'compact' => false, 'skip' => []])
@php
    use App\Support\Money; use App\Billing\InvoiceState; use App\Offers\CommissionState;
    $offer = $deal->offer;
    $invoices = $deal->invoices()->with(['party', 'claims.media', 'payLinks.attempts', 'payLinks.creator', 'payLinks.payerUser', 'deal.offer'])->get();
    // Разовые оплаты по ссылке с машиной сделки (09.10.2026): их деньги лежат в счетах сделки зачётом, что не легло — сверх.
    $credits = $deal->credits()->with(['party', 'offer', 'payments', 'payLinks.attempts', 'payLinks.creator', 'payLinks.payerUser'])->get();
    $extra = $credits->isNotEmpty() ? $deal->creditExtra() : 0;
    $fee = $invoices->first(fn ($i) => $i->isAgentFee());
    $issued = $invoices->reject(fn ($i) => $i->isOwed());
    $state = $deal->commissionState();
    $party = $deal->buyer ? \App\Billing\Party::forUser($deal->buyer, false) : null;
    // Этап «Проверка оплаты» сам показывает заявку менеджера с «Поступило» — здесь она только строкой, без второй пары кнопок.
    // Шаг оплаты сам несёт «Деньги пришли» (`x-route.path`) — у строки счёта её тогда нет.
    $payStep = $deal->isActive() && $offer->position()?->stage->isPayStep();
    $inStep = $deal->isActive() && $offer->position()?->stage->exitsFor(\App\Workflow\Actor::Staff, $deal)->contains(fn ($x) => str_starts_with(mb_strtolower($x->label), 'оплата получена'));
    // Первый счёт на этапе оплаты предлагает сам шаг пути — здесь его второй раз не ставим.
@endphp
<x-ui.card title="Деньги" {{ $attributes }}>
    {{-- «Изменить» — лаймовым словом в заголовке, как у ТС, цен, ДКП и заметки (07.10.2026), а не чипом среди схемы. --}}
    {{-- Гаражная Совкомбанка (`namesBuyer`): вознаграждение назначаем, когда менеджер назвал цену продажи. --}}
    @if ($deal->isActive() && $deal->moneyEditable() && (! $deal->isGarage() || $deal->isGarageManager() || ($deal->namesBuyer() && $deal->amount !== null)))
        <x-slot:actions>
            <div data-controller="sheet" class="contents">
                <button type="button" class="edit-card-toggle" data-action="sheet#open">{{ match (true) { $deal->isGarageManager() && ! $deal->share => 'Вписать долю', $deal->namesBuyer() && $deal->commission === null => 'Назначить вознаграждение', default => 'Изменить' } }}</button>
                <x-ui.sheet id="deal-money-{{ $deal->id }}" title="Деньги сделки">
                    @if ($deal->isGarageManager())
                        {{-- Гаражная «платит менеджер»: наша доля — счёт со ссылкой встаёт сам (`SyncDealInvoices`). --}}
                        <form method="post" action="/work/deals/{{ $deal->id }}/money" class="flex flex-col gap-4">
                            @csrf @method('put')
                            <x-ui.field name="share" label="Наша доля, ₽" :value="$deal->share ? Money::nums($deal->share) : null"/>
                            <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                        </form>
                    @else
                        {{-- Схема — своей строкой «Схема» (`x-deal.scheme`, 03.11.2026): здесь только вознаграждение. --}}
                        <x-offer.money-form :action="'/work/deals/'.$deal->id.'/money'" method="put" :amount="$deal->amount" :cost="$deal->cost" :commission="$deal->commission" :fee="! $deal->isDirect()" :mode="$deal->commission_mode"
                            :scheme="\App\Offers\DealScheme::choiceFor($deal->offer->vendor?->deal_format, $deal->schemeOf())" scheme-locked :owner-price="$deal->owner_price" :owner-locked="true" :supplier-cost="$deal->supplier_cost" submit="Сохранить"/>
                    @endif
                </x-ui.sheet>
            </div>
        </x-slot:actions>
    @endif
    @php
        // Строки расклада: [подпись, сумма, класс суммы, главная]. В карточке строки (compact) видны главные — цена и «Нам»,
        // остальное — под «Расклад» (07.10.2026, владелец: «слишком много»), на странице сделки — всё.
        $dd = 'nums text-right';
        $rows = [];
        // skip — что окно машины уже сказало выше (12.11.2026, владелец: «закупочная несколько раз»): число шапки (`amount` —
        // цена сделки, `cost` — закупочная гаража до продажи) и кто платит поставщику (`payer` — в сведениях под менеджером).
        $skips = fn (string $k) => in_array($k, $skip, true);
        if ($deal->isGarage()) {
            // Гаражная: цены нет, вознаграждение назначат при продаже из гаража; важно, кто платит поставщику.
            // Совкомбанк после продажи — «без оплаты, счёт ПРАЙМ при продаже» уже случилось: цена продажи ниже, схема выше
            // (12.11.2026; в узкой колонке строка шла в три этажа).
            if (! $skips('payer') && ! ($deal->namesBuyer() && $deal->amount !== null)) $rows[] = ['В гараж', $deal->garagePayerLine(), 'text-right font-medium', true];
            if ($deal->namesBuyer() && $deal->amount !== null) $rows[] = ['Цена продажи', Money::rub($deal->amount), $dd.' font-medium', true];
        } elseif (! $skips('amount')) {
            $rows[] = [$deal->isAuction() ? 'Цена на аукционе' : 'Цена подтверждения', $deal->amount === null ? 'не указана' : Money::rub($deal->amount), $dd.' font-medium', true];
        }
        // Закупочная — закреплённая с подтверждения менеджера (её он видит); поставщик скинул после — своей строкой, нам.
        if (! $skips('cost')) $rows[] = [$deal->supplier_cost !== null ? 'Закупочная у менеджера' : 'Закупочная', $deal->cost === null ? 'не указана' : Money::rub($deal->cost), $dd, false];
        if ($deal->supplier_cost !== null) $rows[] = ['Закупочная поставщика', Money::rub($deal->supplier_cost), $dd, false];
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
        if (! $deal->isAuction() && (! $deal->isGarage() || ($deal->namesBuyer() && $deal->amount !== null))) $rows[] = ['Вознаграждение', $deal->commission ? Money::rub($deal->commission) : 'нет', $dd, false];
        $ours = $deal->ours() === null ? null : $deal->ours() + $deal->supplierGain();
        if ($ours !== null) $rows[] = ['Нам', Money::rub($ours), $dd.' text-lg font-semibold'.($ours < 0 ? ' text-danger' : ''), true];
        $rest = $compact ? array_filter($rows, fn ($r) => ! $r[3]) : [];
        $shown = $compact ? array_filter($rows, fn ($r) => $r[3]) : $rows;
    @endphp
    {{-- Схема — первой строкой: кто кому платит решает всё ниже (03.11.2026). --}}
    @unless ($deal->isAuction())<x-deal.scheme :deal="$deal"/>@endunless
    <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
        @foreach ($shown as [$dt, $value, $class])<dt class="text-sm text-ink-dim">{{ $dt }}</dt><dd class="text-right {{ $class }}">{{ $value }}</dd>@endforeach
    </dl>
    @if ($rest)
        <details class="group mt-1.5">
            <summary class="flex cursor-pointer list-none items-center gap-1 text-sm text-ink-muted">Расклад<x-ui.icon name="chevron-down" class="size-4 transition-transform group-open:rotate-180"/></summary>
            <dl class="mt-1.5 grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
                @foreach ($rest as [$dt, $value, $class])<dt class="text-sm text-ink-dim">{{ $dt }}</dt><dd class="text-right {{ $class }}">{{ $value }}</dd>@endforeach
            </dl>
        </details>
    @endif
    {{-- Пустая строка (ни режима, ни состояния, ни «Ещё счёт») не рисуется — под схемой была дыра (12.11.2026). --}}
    @php ob_start(); @endphp
        {{-- Схема и режим вознаграждения — текстом, состояние словом, «Ещё счёт» — действием (10.10.2026). --}}
        {{-- Схема — строкой «Схема» выше (`x-deal.scheme`); здесь — аукцион вне системы и режим вознаграждения. --}}
        @if (($deal->isAuction() && $invoices->isEmpty()) || ($deal->commission && $deal->isPrime()))<span class="text-sm text-ink-muted">{{ $deal->isAuction() && $invoices->isEmpty() ? 'Счёт вне системы' : 'Вознаграждение: '.mb_strtolower($deal->commission_mode->label()) }}</span>@endif
        @if (! in_array($state, [CommissionState::Hidden, CommissionState::Withheld]))<x-ui.state :tone="$state->tone()">{{ mb_strtolower($state->label()) }}{{ $state === CommissionState::Payable && $fee ? ' до '.\App\Support\Ago::day($fee->due_at) : '' }}{{ $state === CommissionState::Paid && $fee?->paid_at ? ' '.\App\Support\Ago::day($fee->paid_at) : '' }}</x-ui.state>@endif
        @if ($deal->commission && ! $deal->withholds() && $party && ! $party->payoutReady())<x-ui.state tone="urgent">Реквизитов для выплаты нет</x-ui.state>@endif
        @if ($deal->isActive())
            {{-- Счета ставятся сами по схеме (`SyncDealInvoices`); здесь — только «Ещё счёт» для редкого ручного. --}}
            @if ($issued->isNotEmpty())<a href="/work/invoices/new?offer={{ $offer->number }}" class="btn btn-s btn-quiet ml-auto"><x-ui.icon name="plus" class="size-4"/> Ещё счёт</a>@endif
        @endif
    @php $chips = trim(ob_get_clean()); @endphp
    @if ($chips !== '')<div class="mt-3 flex flex-wrap items-center gap-1.5">{!! $chips !!}</div>@endif
    {{-- Ссылки на оплату — одной группой строк `x-billing.link-row` (09.10.2026): ссылка счёта сделки и ручные, засчитанные
         в неё; у открытой — «Скопировать» в самой строке. Сверх сделки — строкой там же, словом, не красным (владелец:
         «может, дополнительно договорились»); до счёта — ляжет потом. --}}
    @php
        // Ссылка знает свой счёт без запроса: строка берёт из него плательщика, машину и текст «Поделиться».
        $last = fn ($i, bool $live) => $i->payLinks->when($live, fn ($c) => $c->where('state', '!=', \App\Billing\Acquiring\PayLinkState::Canceled))
            ->sortByDesc('id')->first()?->setRelation('invoice', $i);
        // `toBase`: пустой список счетов — коллекция моделей, и её `merge` брал ключ модели у массива ручной ссылки (05.11.2026,
        // 2610222547: ручная ссылка есть, счетов сделки нет — окно падало).
        // Ссылка счёта сделки — в самой карточке счёта ниже (13.11.2026, владелец: «почему это два разных блока»); здесь —
        // только оплаты вне счёта: разовые ссылки с машиной сделки.
        $links = $credits->toBase()->map(fn ($c) => [$last($c, false), $c->state === InvoiceState::Paid ? 'вручную, в зачёт сделки' : 'вручную, засчитаем'])
            // Отменённая ссылка — мёртвая строка «отменена» рядом с теми же деньгами строкой ниже (12.11.2026, владелец: «мусор»).
            ->filter(fn ($p) => $p[0] && $p[0]->state !== \App\Billing\Acquiring\PayLinkState::Canceled);
    @endphp
    @if ($links->isNotEmpty() || $extra > 0)
        {{-- Живых ссылок нет, только внесённое вручную — одна строка без подписи «Ссылки на оплату». --}}
        @if ($links->isNotEmpty())<div class="list-cap mt-4">Оплаты вне счёта</div>@endif
        <div @class(['list', 'mt-4' => $links->isEmpty()])>
            @foreach ($links as [$l, $sub])
                <x-billing.link-row :link="$l" :car="false" :sub="$sub">
                    @if ($l->isOpen())
                        <x-slot:acts>
                            <x-ui.share-link variant="buttons" :url="$l->url()" title="Оплата по ссылке" :text="$l->message()"/>
                        </x-slot:acts>
                    @endif
                </x-billing.link-row>
            @endforeach
            @if ($extra > 0)
                @php
                    // Деньги по VIN из выписки (12.11.2026) — «По выписке» с датой, не «вручную»; счёта ещё нет — сходится ли
                    // с ним: «не хватает» красным числом (`Deal::arrivedBalance`), до продажи — против закупочной.
                    $bank = $credits->flatMap->payments->first(fn ($p) => $p->source === \App\Billing\PaymentSource::Bank);
                    $arrived = $deal->arrivedBalance();
                    $short = $arrived && $arrived['diff'] <= -1;
                    $over = $arrived && $arrived['against'] === 'invoice' && $arrived['diff'] >= 1;
                @endphp
                <div class="row">@if ($links->isEmpty())<span class="min-w-0 flex-1"><span class="block">{{ $bank ? 'По выписке' : 'Оплачено вручную' }}@if ($bank && $bank->paid_at) <span class="text-ink-muted">{{ $bank->paid_at->format('d.m') }}</span>@endif</span><span class="row-sub">@if ($short)<span class="whitespace-nowrap">{{ $arrived['against'] === 'cost' ? 'до закупочной не хватает' : 'не хватает' }} <span class="nums text-danger">{{ Money::rub(abs($arrived['diff'])) }}</span></span>@elseif ($over)<span class="whitespace-nowrap">переплата <span class="nums">{{ Money::rub($arrived['diff']) }}</span></span>@else{{ $deal->agreed() && $issued->isNotEmpty() ? 'сверх сделки' : 'засчитаем, когда выставим счёт' }}@endif</span></span>@else<span class="min-w-0 flex-1 text-ink-muted">{{ $deal->agreed() && $issued->isNotEmpty() ? 'Сверх сделки' : 'Засчитаем, когда выставим счёт' }}</span>@endif<span class="nums shrink-0">{{ Money::rub($extra) }}</span></div>
            @endif
        </div>
    @endif
    @if ($invoices->isNotEmpty())
        {{-- Счета — одной группой строк через линию, как список в приложении: слева счёт и его состояние словом,
             справа сумма; «Выплатить» — только у вознаграждения к выплате. --}}
        <div class="list mt-4">
            @foreach ($invoices as $i)
                @php
                    // Ссылка счёта — в его же карточке: её состояние вместо светофора, «Скопировать» и «Отправить» под строкой.
                    $link = ! $i->isOwed() && $i->state !== InvoiceState::Paid ? $last($i, true) : null;
                    [$linkState, $linkTone] = $link ? \App\Http\Admin\MoneyLinks::short($link) : [null, null];
                    $manual = ! $i->isOwed() && $i->state === InvoiceState::Issued && $i->remaining() > 0 && ! $payStep && auth()->user()?->canManageCrm();
                @endphp
                <div class="row">
                    <a href="/work/money/invoices/{{ $i->id }}" class="min-w-0 flex-1">
                        <span class="block">{{ $i->isOwed() ? 'Выплата менеджеру' : 'Счёт '.$i->label() }}</span>
                        {{-- Кому — своей строкой и целиком (06.11.2026: рядом с суммой «Макаров Дмитрий Сергее…»). --}}
                        @unless ($i->isOwed())<x-vendor.name :party="$i->party" class="vendor-name-wrap text-sm text-ink-muted"/>@endunless
                        <span class="row-sub">@if ($linkState)<span class="{{ $linkTone }}">ссылка: {{ $linkState }}</span>@else<x-billing.light :invoice="$i"/>@endif @if ($i->isPartial())<span class="tag nums">из {{ Money::rub($i->total) }}</span>@endif</span>
                    </a>
                    <span class="nums shrink-0 font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
                    @if ($i->isAgentFee() && $i->state === InvoiceState::Issued)
                        <span data-controller="sheet" class="contents">
                            <x-ui.button type="button" size="sm" data-action="sheet#open">Выплатить</x-ui.button>
                            <x-ui.sheet id="pay-{{ $i->id }}" :title="'Выплата '.$i->party->name"><x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" sheet/></x-ui.sheet>
                        </span>
                    @endif
                </div>
                {{-- Под счётом — что с ним делать (13.11.2026, владелец: «Деньги пришли» с галкой читалось как «оплачено»): ссылка —
                     «Скопировать» и «Отправить», оплатили мимо неё — тихой кнопкой «Оплатили без ссылки…» (шторка оплаты; на шаге оплаты
                     её несёт шаг). Открытой ссылки нет, а остаток есть — «Новая ссылка на оплату» (`pay-status`). --}}
                @if (($link && $link->isOpen()) || $manual)
                    <div class="invoice-acts">
                        @if ($link && $link->isOpen())<x-ui.share-link variant="buttons" class="!ml-0" :url="$link->url()" title="Оплата по ссылке" :text="$link->message()"/>@endif
                        @if ($manual)
                            <span data-controller="sheet" class="contents">
                                <button type="button" class="act-quiet ml-auto" data-action="sheet#open">Оплатили без ссылки…</button>
                                <x-ui.sheet id="paid-{{ $i->id }}" :title="'Оплата по счёту '.$i->label()"><x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" sheet/></x-ui.sheet>
                            </span>
                        @endif
                    </div>
                @endif
                @if (! $i->isOwed() && ! $i->openLink())<x-billing.pay-status :invoice="$i" staff/>@endif
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
