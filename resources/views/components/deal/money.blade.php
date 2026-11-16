{{-- Деньги сделки для сотрудника: расклад (цена, закупочная снимком, разница, вознаграждение, нам; по ДКП — взаимозачёт,
     собственнику, подбор), чипы —
     режим, состояние вознаграждения, «Изменить» до счёта, «Счёт»; ниже счета строками, заявка менеджера
     об оплате с решением под своим счётом, у вознаграждения к выплате — «Выплатить» в самой строке. Под неоплаченным
     счётом — его ссылка на оплату строкой группы «Ссылки на оплату» с «Скопировать» (`x-billing.link-row`): начальник открыл
     сделку и сразу видит, оплатили ли и чем поделиться. Менеджеру ничего из этого не показывается. compact — карточка строки «Сделок»:
     цена и «Нам», остальной расклад свёрнут. 07.10.2026: кнопки шторок .sheet-foot. --}}
@props(['deal', 'compact' => false, 'skip' => [], 'window' => false])
@php
    use App\Support\Money; use App\Billing\InvoiceState; use App\Offers\CommissionState; use App\Offers\CommissionMode;
    $offer = $deal->offer;
    // Строка «Плательщик» (`x-deal.payer`, 16.11.2026) — у идущей сделки с менеджером: над счетами, без счёта — своей группой.
    $payerRow = $deal->buyer_id && $deal->isActive() && ! $deal->isAuction();
    $payerId = $payerRow ? ($deal->payer_party_id ?? $deal->payerUser(sale: $deal->isPrime())?->party_id) : null;
    $invoices = $deal->invoices()->with(['party', 'media', 'claims.media', 'payLinks.attempts', 'payLinks.creator', 'payLinks.payerUser', 'deal.offer'])->get();
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
@php
    $scheme = $deal->schemeOf();
    // Договор нашего юрлица — строкой «Сумма» (`x-deal.contract-price`: сумма и НДС шторкой).
    $contractRow = ! $deal->isAuction() && $deal->hasContract() && $scheme->paysCar();
@endphp
{{-- «Продажа» (15.11.2026, владелец: «„Деньги“ — нейрослопово», «один блок „Продажа“, второй „Оплата поставщику“»): кто продаёт,
     договор и покупатель (`x-deal.contract bare`, `x-deal.buyer`), сумма договора, наши счета и сколько остаётся нам. Что уходит за
     машину — соседним блоком `x-deal.supplier-payment`. --}}
@php
    // Договор и покупатель — строками этого блока: на странице всегда, в окне — покупатель, пока его данных нет.
    // `window` — окно машины (13.11.2026: там покупатель, пока его данных нет; договор целиком — на странице).
    $withContract = ! $window && $deal->hasContract() && ! $deal->isAuction();
    $withBuyer = ! $withContract && $deal->isLive() && ($deal->collectsBuyer() || $deal->contract?->buyer_user_id) && (! $window || $deal->buyerGap() !== null);
@endphp
<x-ui.card title="Продажа" {{ $attributes->class('sale-card') }}>
    @php
        // Редкое со счетами — в «⋯» карточки (15.11.2026, владелец выбрал «счёт файлом, как документ»): «Оплатили без ссылки…»
        // (шторка оплаты ниже, событием `paid-{id}:open`), «Ещё счёт», страница счёта. С 16.11.2026 и на шаге оплаты: «Деньги пришли»
        // из шага убрано — отметка нашей оплаты здесь, строкой «Деньги пришли?» под счётом.
        $paidBy = $invoices->filter(fn ($i) => ! $i->isOwed() && $i->state === InvoiceState::Issued && $i->remaining() > 0 && auth()->user()?->canManageCrm());
        $pages = $invoices->reject(fn ($i) => $i->isOwed() || $i->state === InvoiceState::Void);
        $editMoney = $deal->isActive() && $deal->moneyEditable() && (! $deal->isGarage() || $deal->isGarageManager() || ($deal->namesBuyer() && $deal->amount !== null));
        $moreMoney = $editMoney || $withContract || $paidBy->isNotEmpty() || $pages->isNotEmpty() || ($deal->isActive() && $issued->isNotEmpty());
    @endphp
    {{-- «Изменить» — лаймовым словом в заголовке, как у ТС, цен, ДКП и заметки (07.10.2026), а не чипом среди схемы. --}}
    {{-- Гаражная Совкомбанка (`namesBuyer`): вознаграждение назначаем, когда менеджер назвал цену продажи. --}}
    @if ($editMoney || $moreMoney)
        <x-slot:actions>
            <span class="flex items-center gap-3">
            @if ($moreMoney)
                <x-ui.more :id="'money-more-'.$deal->id">
                    @if ($editMoney || $withContract)
                        <div class="menu-group">
                            @if ($editMoney)<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-money-{{ $deal->id }}:open">{{ match (true) { $deal->namesBuyer() && $deal->commission === null => 'Назначить вознаграждение…', default => 'Вознаграждение менеджера…' } }}</button>@endif
                            @if ($withContract)<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="dkp-{{ $deal->id }}:open">Данные договора…</button>@endif
                        </div>
                    @endif
                    <div class="menu-group">
                        @foreach ($paidBy as $i)
                            <button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="paid-{{ $i->id }}:open">Оплатили без ссылки{{ $paidBy->count() > 1 ? ' по счёту '.$i->label() : '' }}…</button>
                        @endforeach
                        @if ($deal->isActive() && $issued->isNotEmpty())<a href="/work/invoices/new?offer={{ $offer->number }}" class="menu-item w-full">Ещё счёт</a>@endif
                    </div>
                    @if ($pages->isNotEmpty())
                        <div class="menu-group">
                            @foreach ($pages as $i)<a href="/work/money/invoices/{{ $i->id }}" class="menu-item w-full" data-turbo-frame="_top">Страница счёта {{ $i->label() }}</a>@endforeach
                        </div>
                    @endif
                </x-ui.more>
            @endif
            @if ($editMoney)
            {{-- Шторка денег сделки — пунктом «⋯» (15.11.2026: «Изменить» в заголовке «Продажи» было непонятно, что меняет). --}}
            <div data-controller="sheet" class="contents" data-action="deal-money-{{ $deal->id }}:open@window->sheet#open">
                <x-ui.sheet id="deal-money-{{ $deal->id }}" title="Деньги сделки">
                    @if ($deal->isGarageManager())
                        {{-- Гаражная «платит менеджер»: вознаграждение менеджеру ползунком (16.11.2026), в форму — «нам по ссылке», счёт со
                             ссылкой встаёт сам (`SyncDealInvoices`). --}}
                        <form method="post" action="/work/deals/{{ $deal->id }}/money" class="flex flex-col gap-4">
                            @csrf @method('put')
                            <x-deal.reward-split name="share" mode="ours" :id="'deal-share-'.$deal->id" :value="$deal->share" :margin="$deal->offer?->asking_price && $deal->cost !== null ? (int) $deal->offer->asking_price - (int) $deal->cost : null"/>
                            <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                        </form>
                    @else
                        {{-- Схема — своей строкой «Схема» (`x-deal.scheme`, 03.11.2026): здесь только вознаграждение. --}}
                        <x-offer.money-form :action="'/work/deals/'.$deal->id.'/money'" method="put" :amount="$deal->amount" :cost="$deal->cost" :commission="$deal->commission" :fee="! $deal->isDirect()" :mode="$deal->commission_mode"
                            :scheme="\App\Offers\DealScheme::choiceFor($deal->offer->vendor?->deal_format, $deal->schemeOf())" scheme-locked :owner-price="$deal->owner_price" :owner-locked="true" :supplier-cost="$deal->supplier_cost" :share="$deal->isGarage() ? null : $deal->buyer?->deal_share" submit="Сохранить"/>
                    @endif
                </x-ui.sheet>
            </div>
            @endif
            </span>
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
            if (! $skips('payer') && ! ($deal->namesBuyer() && $deal->amount !== null)) $rows[] = ['В гараж', $deal->garagePayerLine(), 'text-right font-medium', true];
            if ($deal->namesBuyer() && $deal->amount !== null) $rows[] = ['Цена продажи', Money::rub($deal->amount), $dd.' font-medium', true];
            if ($deal->isGarageManager()) $rows[] = ['Нам по ссылке', $deal->share ? Money::rub($deal->share) : 'не вписано', $dd, false];
        } elseif ($deal->isAuction() && ! $skips('amount')) {
            $rows[] = ['Цена на аукционе', $deal->amount === null ? 'не указана' : Money::rub($deal->amount), $dd.' font-medium', true];
        }
        // Закупочная, разница, «страховой» — в «Оплате поставщику»; вознаграждение — вторым этажом «Нам» (15.11.2026: раскладка
        // из пяти строк повторяла соседние блоки).
        // Вознаграждение — своей строкой над «Нам», как платится — в её подписи (15.11.2026, владелец: «кривая вёрстка» — подпись
        // под «Нам» в три этажа). Реквизитов для выплаты нет — меткой ниже.
        $feeNoPayout = $deal->commission && ! $deal->withholds() && $party && ! $party->payoutReady();
        $feeShown = $deal->commission && ! $deal->isAuction() && (! $deal->isGarage() || ($deal->namesBuyer() && $deal->amount !== null));
        if ($feeShown) $rows[] = [match (true) {
            $deal->selfBuy() => 'Вычтено из оплаты менеджера',
            $deal->isPrime() && $deal->commission_mode === CommissionMode::Withheld => 'Менеджер оставляет себе',
            $deal->isPrime() => 'Выплачиваем менеджеру',
            default => 'Вознаграждение менеджера',
        }, Money::rub($deal->commission), $dd, false];
        $ours = $deal->ours() === null ? null : $deal->ours() + $deal->supplierGain();
        if ($ours !== null) $rows[] = ['Нам', Money::rub($ours), $dd.' text-lg font-semibold'.($ours < 0 ? ' text-danger' : ''), true];
        $rest = $compact ? array_filter($rows, fn ($r) => ! $r[3]) : [];
        $shown = $compact ? array_filter($rows, fn ($r) => $r[3]) : $rows;
    @endphp
    {{-- Кто продаёт (когда есть выбор), договор и что переводим поставщику по договору комиссии — одной группой строк
         (15.11.2026; было две плашки и подпись «По договору комиссии» между ними). --}}
    @php $supplierBlock = in_array($deal->schemeOf(), [\App\Offers\DealScheme::Insurer, \App\Offers\DealScheme::OwnerDkp], true) && $deal->buyer_id && ! $deal->isAuction(); @endphp
    @php ob_start(); @endphp
    @unless ($deal->isAuction())<x-deal.scheme :deal="$deal" bare :price="false"/>@endunless
    @if ($withContract)<x-deal.contract :deal="$deal" bare :doc-row="false"/>@elseif ($withBuyer)<x-deal.buyer :deal="$deal"/>@endif
    {{-- Покупатель платит поставщику сам — его счёт и платёжка здесь, под «Реквизитами» (карточки «Оплата поставщику» тогда нет). --}}
    @if ($supplierBlock)@include('admin.deals.supplier-rows')@endif
    {{-- Чего не хватает договору с нашей стороны — владелец, ПТС, сумма (16.11.2026: было делами в «Ходе сделки»). --}}
    <x-deal.contract-gaps :deal="$deal" :price-row="$contractRow"/>
    @if ($contractRow)<x-deal.contract-price :deal="$deal" :contract="\App\Offers\DealContract::for($deal)->loadMissing('priceAuthor')"/>@endif
    {{-- Готовый договор — сразу над подписанным (16.11.2026, владелец). --}}
    @if ($withContract)<x-deal.contract-doc :deal="$deal"/>@endif
    {{-- Подписанный договор вне системы (15.11.2026) — строками той же группы. --}}
    @if (auth()->user()?->canEditDeals())<x-deal.signed-contract :deal="$deal" :base="'/work/deals/'.$deal->id" bare/>@endif
    @php $top = trim(ob_get_clean()); @endphp
    {{-- Покупатель платит поставщику или собственнику сам («страховой напрямую», ДКП) — эта группа и есть блок «Поставщику»:
         заголовок с суммой, как у блока «ООО «ПРАЙМ»» ниже (16.11.2026, владелец: оба плательщика — одним видом). --}}
    @if ($top !== '' && $supplierBlock)
        <div class="mb-2 flex items-baseline gap-3 px-3">
            <span class="min-w-0 flex-1 font-medium">{{ $deal->schemeOf() === \App\Offers\DealScheme::OwnerDkp ? 'Собственнику' : 'Поставщику' }}</span>
            <span class="nums shrink-0">{{ $deal->isDirect() && $deal->owner_price === null ? 'уточним' : Money::rub((int) $deal->ownerPrice()) }}</span>
        </div>
    @endif
    @if ($top !== '')<div class="list mb-3 last:mb-0">{!! $top !!}</div>@endif
    {{-- Пустая строка (ни режима, ни состояния, ни «Ещё счёт») не рисуется — под схемой была дыра (12.11.2026). --}}
    @php ob_start(); @endphp
        {{-- Схема и режим вознаграждения — текстом, состояние словом, «Ещё счёт» — действием (10.10.2026). --}}
        {{-- Схема — строкой «Схема» выше (`x-deal.scheme`); здесь — аукцион вне системы и режим вознаграждения. --}}
        {{-- Оформляет на себя (13.11.2026): вознаграждение вычитается из того, что он платит, — словами, при любой схеме. --}}
        {{-- На странице и в окне «как» и «реквизитов нет» — у строки «Вознаграждение» (15.11.2026); здесь — в карточке строки. --}}
        @if ($deal->isAuction() && $invoices->isEmpty())
            <span class="text-sm text-ink-muted">Счёт вне системы</span>
        @elseif ($compact && $deal->selfBuy() && $deal->commission)
            <span class="text-sm text-ink-muted">На себя, вознаграждение вычтено из оплаты</span>
        @elseif ($compact && $deal->commission && $deal->isPrime())
            <span class="text-sm text-ink-muted">Вознаграждение: {{ mb_strtolower($deal->commission_mode->label()) }}</span>
        @endif
        @if (! in_array($state, [CommissionState::Hidden, CommissionState::Withheld]))<x-ui.state :tone="$state->tone()">{{ mb_strtolower($state->label()) }}{{ $state === CommissionState::Payable && $fee ? ' до '.\App\Support\Ago::day($fee->due_at) : '' }}{{ $state === CommissionState::Paid && $fee?->paid_at ? ' '.\App\Support\Ago::day($fee->paid_at) : '' }}</x-ui.state>@endif
        @if ($feeNoPayout)<span class="text-sm text-ink-muted">Реквизитов для выплаты нет</span>@endif
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
    @if ($payerRow && $invoices->isEmpty())
        <div class="list mt-4"><x-deal.payer :deal="$deal" :action="'/work/deals/'.$deal->id.'/payer'" :editable="auth()->user()?->canManageCrm()"/></div>
    @endif
    @if ($invoices->isNotEmpty())
        {{-- Счета — одной группой строк через линию, как список в приложении: слева счёт и его состояние словом,
             справа сумма; «Выплатить» — только у вознаграждения к выплате. --}}
        {{-- Блок «ООО «ПРАЙМ»» / «Р.О.С.» (16.11.2026, вариант Б, владелец: «оплата поставщику — у него свой покупатель, оплата
             ООО «ПРАЙМ» — у него свой плательщик»): заголовок — кому платят по нашим счетам и сколько ещё, под ним плательщик и счета. --}}
        @php
            $ourBills = $invoices->reject(fn ($i) => $i->isOwed() || $i->state === InvoiceState::Void);
            $ourSeller = $ourBills->first()?->seller;
        @endphp
        @if ($ourSeller)
            <div class="mt-4 flex items-baseline gap-3 px-3">
                {{-- Заголовок — за что платят, а не кому (16.11.2026, владелец: «не „ООО «ПРАЙМ» 98 000“, а „Услуги подбора 98 000“
                     по аналогии с „Поставщику“»); кому — строкой «Плательщик платит» (у подбора) или «Покупатель платит» вверху. --}}
                <span class="min-w-0 flex-1 font-medium">{{ match ($ourBills->first()->kind) { \App\Billing\ChargeKind::Selection => 'Услуги подбора', \App\Billing\ChargeKind::Sale => 'Транспортное средство', default => $ourSeller->config()['name'] } }}</span>
                <span class="nums shrink-0">{{ Money::rub($ourBills->sum(fn ($i) => $i->state === InvoiceState::Paid ? $i->total : $i->remaining())) }}</span>
            </div>
        @endif
        <div @class(['list', 'mt-2' => $ourSeller, 'mt-4' => ! $ourSeller])>
            {{-- Плательщик — один на счёт и ссылку, строкой над ними (16.11.2026, `x-deal.payer`). --}}
            @if ($ourBills->contains(fn ($i) => $i->kind === \App\Billing\ChargeKind::Selection) && $deal->buyer_id)<x-deal.fee-seller :deal="$deal"/>@endif
            @if ($payerRow)<x-deal.payer :deal="$deal" :action="'/work/deals/'.$deal->id.'/payer'" :editable="auth()->user()?->canManageCrm()"/>@endif
            @foreach ($invoices as $i)
                @php
                    $link = ! $i->isOwed() && $i->state !== InvoiceState::Paid ? $last($i, true) : null;
                    [$linkState, $linkTone] = $link ? \App\Http\Admin\MoneyLinks::short($link) : [null, null];
                    $media = ! $i->isOwed() && $i->state !== InvoiceState::Void ? $i->getFirstMedia('file') : null;
                    $pdf = $media ? '/work/money/invoices/'.$i->id.'/pdf' : null;
                @endphp
                @if ($i->isOwed())
                    <div class="row">
                        <a href="/work/money/invoices/{{ $i->id }}" class="min-w-0 flex-1"><span class="block">Выплата менеджеру</span><span class="row-sub"><x-billing.light :invoice="$i"/></span></a>
                        <span class="nums shrink-0 font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
                        @if ($i->isAgentFee() && $i->state === InvoiceState::Issued)
                            <span data-controller="sheet" class="contents">
                                <x-ui.button type="button" size="sm" data-action="sheet#open">Выплатить</x-ui.button>
                                <x-ui.sheet id="pay-{{ $i->id }}" :title="'Выплата '.$i->party->name"><x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" sheet/></x-ui.sheet>
                            </span>
                        @endif
                    </div>
                @else
                    {{-- Счёт — файлом, как документ (15.11.2026, владелец: «счёт должен быть не страницей, а кнопкой „Скачать PDF“»;
                         из вариантов выбрал «файлом»): значок PDF с весом, «Счёт № 184 от 13.11», ниже кто платит и сумма; нажатие —
                         PDF в шторке документов, справа — «Скачать». Файла нет — строка ведёт на страницу счёта. --}}
                    <x-deal.doc-row :href="$pdf ?? '/work/money/invoices/'.$i->id" :title="'Счёт '.$i->label()" :date="\App\Support\Ago::day($i->issued_at)" name="schet.pdf" :bytes="$media?->size" :download="(bool) $pdf">
                        @if (! $payerRow || $i->party_id !== $payerId)<span>{{ $i->party?->title() }}</span>@endif<span class="nums whitespace-nowrap">{{ $i->isPartial() && $i->remaining() > 0 ? 'осталось '.Money::rub($i->remaining()).' из '.Money::rub($i->total) : Money::rub($i->total) }}</span>@if ($i->deal_id && $i->shown_at)<span class="text-ink-muted">у менеджера с {{ \App\Support\Ago::day($i->shown_at) }}</span>@elseif (! $i->isShownToManager())<span class="text-ink-muted">не отправлен</span>@endif
                    </x-deal.doc-row>
                    {{-- Менеджер видит счёт только после «Отправить» (16.11.2026): сотрудник сперва проверяет. --}}
                    @if (! $i->isShownToManager() && $i->state !== InvoiceState::Void)<x-deal.send-row :deal="$deal" :action="'/work/deals/'.$deal->id.'/invoices/'.$i->id.'/show'"/>@endif
                    {{-- Ссылка на оплату — своей строкой под счётом: её состояние и «Скопировать» / «Отправить». --}}
                    @if ($link && $link->isOpen())
                        <x-ui.share-link variant="row" :url="$link->url()" title="Оплата по ссылке" :text="$link->message()" label="Ссылка на оплату" :sub="$linkState" done="Ссылка на оплату в буфере"/>
                    @endif
                    {{-- Отметка, что оплата ПРАЙМ / Р.О.С. принята, — здесь, под своим счётом (16.11.2026, владелец: «отметку, что оплата
                         на прайм/рос принята, — не в шаге, а в блоке «Продажа»»): та же шторка оплаты (`paid-{id}`). --}}
                    @if ($paidBy->contains('id', $i->id))
                        <x-deal.sale-row label="Деньги пришли?" value="Отметить" tone="accent" data-controller="emit" data-action="emit#send" data-emit-event-param="paid-{{ $i->id }}:open"/>
                    @endif
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
    {{-- Шторки «Оплатили без ссылки…» — их открывает пункт «⋯» карточки. --}}
    @foreach ($paidBy as $i)
        <div class="contents" data-controller="sheet" data-action="paid-{{ $i->id }}:open@window->sheet#open">
            <x-ui.sheet id="paid-{{ $i->id }}" :title="'Оплата по счёту '.$i->label()"><x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" sheet/></x-ui.sheet>
        </div>
    @endforeach
    {{-- Итог — последним: сначала договор и счета, потом сколько остаётся нам (15.11.2026). --}}
    @if ($shown)
        <dl class="mt-4 grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
            @foreach ($shown as $r)<dt class="text-sm text-ink-dim">{{ $r[0] }}@foreach ($r[4] ?? [] as [$subText, $subTone])<span class="block {{ $subTone }}">{{ $subText }}</span>@endforeach</dt><dd class="text-right {{ $r[2] }}">{{ $r[1] }}</dd>@endforeach
        </dl>
    @endif
    @if ($rest)
        <details class="group mt-1.5">
            <summary class="flex cursor-pointer list-none items-center gap-1 text-sm text-ink-muted">Расклад<x-ui.icon name="chevron-down" class="size-4 transition-transform group-open:rotate-180"/></summary>
            <dl class="mt-1.5 grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
                @foreach ($rest as [$dt, $value, $class])<dt class="text-sm text-ink-dim">{{ $dt }}</dt><dd class="text-right {{ $class }}">{{ $value }}</dd>@endforeach
            </dl>
        </details>
    @endif
</x-ui.card>
