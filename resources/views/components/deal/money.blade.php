{{-- «Продажа» сделки для сотрудника (18.11.2026, владелец: «по несколько раз одно и то же», выбрал вариант А): блок на каждого, кому
     платят, — у ПРАЙМ / Р.О.С. наш договор и счёт за ТС, у ДКП и «страховой напрямую» — «Собственнику» / «Поставщику», и «Услуги
     подбора». Шапка блока — кому и одна сумма (у нашего договора нажатием — сумма и НДС), под ней одно слово о деньгах
     (`Billing\PayWord`); в списке — покупатель, чего не хватает договору, договор одной строкой, счёт без суммы, ссылка, отметки.
     Кому платит покупатель, кто платит, кому подбор, вознаграждение, данные договора — пунктами «⋯». Внизу — «Вознаграждение
     менеджера» и у ПРАЙМ «Услуги подбора». Менеджеру ничего из этого не показывается. compact — карточка строки «Сделок». --}}
@props(['deal', 'compact' => false, 'skip' => [], 'window' => false])
@php
    use App\Support\Money; use App\Billing\InvoiceState;
    $offer = $deal->offer;
    $invoices = $deal->shownInvoices();
    // Строка «Плательщик» (`x-deal.payer`, 16.11.2026) — у идущей сделки с менеджером: над счетами, без счёта — своей группой.
    // Только у счёта за ТС (18.11.2026, владелец: «плательщик указывается только для выставления договора счёта (ДКП), для комиссий
    // нет»): у подбора плательщика нет нигде — ссылку оплачивает кто угодно, на `/pay` — только почта.
    $payerRow = $deal->buyer_id && $deal->isActive() && ! $deal->isAuction()
        && ($deal->schemeOf()->paysCar() || $invoices->contains(fn ($i) => $i->kind === \App\Billing\ChargeKind::Sale && ! $i->isOwed()));
    $payerId = $payerRow ? ($deal->payer_party_id ?? $deal->payerUser(sale: $deal->isPrime())?->party_id) : null;
    // Разовые оплаты по ссылке с машиной сделки (09.10.2026): их деньги лежат в счетах сделки зачётом, что не легло — сверх.
    $credits = $deal->credits()->with(['party', 'offer', 'payments', 'payLinks.attempts', 'payLinks.creator', 'payLinks.payerUser'])->get();
    $extra = $credits->isNotEmpty() ? $deal->creditExtra() : 0;
    $issued = $invoices->reject(fn ($i) => $i->isOwed());
    // Этап «Проверка оплаты» сам показывает заявку менеджера с «Поступило» — здесь она только строкой, без второй пары кнопок.
    // Шаг оплаты сам несёт «Деньги пришли» (`x-route.path`) — у строки счёта её тогда нет.
    $payStep = $deal->isActive() && $offer->position()?->stage->isPayStep();
    // На «Подтверждении оплаты» страховой шаг заявку не показывает (оплату подтверждает поставщик) — кнопки здесь.
    $inStep = $deal->isActive() && $offer->position()?->stage->waits_for !== \App\Workflow\WaitsFor::Supplier && $offer->position()?->stage->exitsFor(\App\Workflow\Actor::Staff, $deal)->contains(fn ($x) => str_starts_with(mb_strtolower($x->label), 'оплата получена'));
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
        $editMoney = $deal->isActive() && $deal->moneyEditable() && (! $deal->isGarage() || $deal->isGarageManager() || ($deal->namesBuyer() && $deal->amount !== null));
        // Что раньше стояло строками («Кому», «Плательщик», «Кому» у подбора) — пунктами «⋯» (18.11.2026): шапки блоков и так это говорят.
        $schemeEdit = ! $deal->isAuction() && $deal->isActive() && count(\App\Offers\DealScheme::casesFor($offer->vendor, $offer)) > 1 && $deal->moneyEditable() && auth()->user()?->canEditDeals();
        $payerEdit = $payerRow && auth()->user()?->canEditDeals() && ! \App\Offers\Actions\SetDealPayer::locked($deal);
        $feeSellerEdit = $deal->buyer_id && auth()->user()?->canEditDeals() && $deal->isActive() && ! \App\Offers\Actions\SetFeeSeller::locked($deal)
            && $invoices->contains(fn ($i) => $i->kind === \App\Billing\ChargeKind::Selection && ! $i->isOwed());
        $moreMoney = $editMoney || $withContract || $schemeEdit || $payerEdit || $feeSellerEdit || $paidBy->isNotEmpty() || ($deal->isActive() && $issued->isNotEmpty());
    @endphp
    {{-- «Изменить» — лаймовым словом в заголовке, как у ТС, цен, ДКП и заметки (07.10.2026), а не чипом среди схемы. --}}
    {{-- Гаражная Совкомбанка (`namesBuyer`): вознаграждение назначаем, когда менеджер назвал цену продажи. --}}
    @if ($editMoney || $moreMoney)
        <x-slot:actions>
            <span class="flex items-center gap-3">
            @if ($moreMoney)
                <x-ui.more :id="'money-more-'.$deal->id">
                    @if ($schemeEdit || $payerEdit || $feeSellerEdit)
                        <div class="menu-group">
                            @if ($schemeEdit)<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="scheme-{{ $deal->id }}:open">Кому платит покупатель…</button>@endif
                            @if ($payerEdit)<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-payer-{{ $deal->id }}:open">Кто платит…</button>@endif
                            @if ($feeSellerEdit)<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="fee-seller-{{ $deal->id }}:open">Кому платят услуги подбора…</button>@endif
                        </div>
                    @endif
                    @if ($editMoney || $withContract)
                        <div class="menu-group">
                            @if ($editMoney)<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-money-{{ $deal->id }}:open">{{ match (true) { $deal->namesBuyer() && $deal->commission === null => 'Назначить вознаграждение…', default => 'Вознаграждение менеджера…' } }}</button>@endif
                            @if ($withContract)<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="dkp-{{ $deal->id }}:open">Данные договора…</button>@endif
                        </div>
                    @endif
                    <div class="menu-group">
                        @foreach ($paidBy as $i)
                            <button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="paid-{{ $i->id }}:open">Оплатили без ссылки{{ $paidBy->count() > 1 ? ($i->isDocument() ? ' по счёту '.$i->label() : ' за подбор') : '' }}…</button>
                        @endforeach
                        @if ($deal->isActive() && $issued->isNotEmpty())<a href="/work/invoices/new?offer={{ $offer->number }}" class="menu-item w-full">Ещё счёт</a>@endif
                    </div>
                </x-ui.more>
            @endif
            @if ($editMoney)
            {{-- Шторка денег сделки — пунктом «⋯» (15.11.2026: «Изменить» в заголовке «Продажи» было непонятно, что меняет). --}}
            <div data-controller="sheet" class="contents" data-action="deal-money-{{ $deal->id }}:open@window->sheet#open">
                <x-ui.sheet id="deal-money-{{ $deal->id }}" title="Деньги сделки">
                    @if ($deal->isGarageManager())
                        {{-- Гаражная «платит менеджер»: вознаграждение и услуги подбора ползунком (16.11.2026), в форму — услуги подбора
                             (`deals.share`), счёт со ссылкой встаёт сам (`SyncDealInvoices`). --}}
                        <form method="post" action="/work/deals/{{ $deal->id }}/money" class="flex flex-col gap-4">
                            @csrf @method('put')
                            <x-deal.reward-split name="share" mode="ours" :id="'deal-share-'.$deal->id" :value="$deal->share" :margin="$deal->offer?->asking_price && $deal->cost !== null ? (int) $deal->offer->asking_price - (int) $deal->cost : null"/>
                            <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                        </form>
                    @else
                        {{-- Схема — своей строкой «Схема» (`x-deal.scheme`, 03.11.2026): здесь только вознаграждение. --}}
                        <x-offer.money-form :action="'/work/deals/'.$deal->id.'/money'" method="put" :amount="$deal->amount" :cost="$deal->cost" :commission="$deal->commission" :fee="! $deal->isDirect()"
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
            // Гаражная: цены нет, вознаграждение посчитаем при продаже из гаража; важно, кто платит поставщику.
            if (! $skips('payer') && ! ($deal->namesBuyer() && $deal->amount !== null)) $rows[] = ['В гараж', $deal->garagePayerLine(), 'text-right font-medium', true];
            if ($deal->namesBuyer() && $deal->amount !== null) $rows[] = ['Цена продажи', Money::rub($deal->amount), $dd.' font-medium', true];
        } elseif ($deal->isAuction() && ! $skips('amount')) {
            $rows[] = ['Цена на аукционе', $deal->amount === null ? 'не указана' : Money::rub($deal->amount), $dd.' font-medium', true];
        }
        // Закупочная, разница, «страховой» — в «Оплате поставщику» (15.11.2026: раскладка из пяти строк повторяла соседние блоки).
        // Нас интересуют закупочная и услуги подбора (18.11.2026, владелец): вознаграждение менеджера — просто числом, без слов
        // о том, как он его получает; то, что остаётся нам сверх закупочной, — «Услуги подбора».
        $feeShown = $deal->commission && ! $deal->isAuction() && (! $deal->isGarage() || ($deal->namesBuyer() && $deal->amount !== null));
        if ($feeShown) $rows[] = ['Вознаграждение менеджера', Money::rub($deal->commission), $dd, false];
        $ours = $deal->ours() === null ? null : $deal->ours() + $deal->supplierGain();
        // Услуги подбора — счётом ниже (ДКП, «страховой», доля гаража): там же заголовком с суммой, второй раз строкой не пишем.
        $feeBilled = ($deal->paysSelection() || $deal->isGarageManager()) && $invoices->contains(fn ($i) => $i->kind === \App\Billing\ChargeKind::Selection && $i->state !== InvoiceState::Void);
        if ($deal->isGarageManager() && ! $feeBilled) {
            $rows[] = ['Услуги подбора', $deal->share ? Money::rub($deal->share) : 'не вписано', $dd.' text-lg font-semibold', true];
        } elseif (! $feeBilled && ! $deal->isGarageManager() && $ours !== null) {
            $rows[] = ['Услуги подбора', Money::rub($ours), $dd.' text-lg font-semibold'.($ours < 0 ? ' text-danger' : ''), true];
        }
        $rest = $compact ? array_filter($rows, fn ($r) => ! $r[3]) : [];
        $shown = $compact ? array_filter($rows, fn ($r) => $r[3]) : $rows;
    @endphp
    @php
        // Блоки «Продажи» — по одному на каждого, кому платят (18.11.2026, владелец: «по несколько раз одно и то же», выбрал вариант А):
        // шапка — кому и одна сумма, под ней одно слово о деньгах (`Billing\PayWord`); в списке — только то, что требует данных
        // или файла. Кому платит покупатель, кто платит и кому подбор — пунктами «⋯»; сумма и НДС договора — нажатием на сумму.
        $supplierBlock = in_array($deal->schemeOf(), [\App\Offers\DealScheme::Insurer, \App\Offers\DealScheme::OwnerDkp], true) && $deal->buyer_id && ! $deal->isAuction();
        $ourBills = $issued->reject(fn ($i) => $i->state === InvoiceState::Void);
        $sale = $ourBills->first(fn ($i) => $i->kind === \App\Billing\ChargeKind::Sale);
        $selection = $ourBills->first(fn ($i) => $i->kind === \App\Billing\ChargeKind::Selection);
        $otherBills = $ourBills->reject(fn ($i) => $i->is($sale) || $i->is($selection));
        $canEdit = auth()->user()?->canEditDeals();
        $contract = $contractRow ? \App\Offers\DealContract::for($deal)->loadMissing('priceAuthor') : null;
        // За ТС платят нам (ПРАЙМ, Р.О.С., гараж): наш договор или счёт за машину.
        $carBlock = ! $deal->isAuction() && ! $supplierBlock && ($contract || $sale);
        $carSum = $sale ? (float) $sale->total : $contract?->price;
        $carWord = $carBlock ? \App\Billing\PayWord::of($sale, $deal) : null;
        // Плательщик строкой — только когда это не покупатель по договору (иначе одно имя дважды); покупателя ещё нет — только
        // выбранный руками (по умолчанию платит сам менеджер, это не новость).
        $buyerParty = $deal->contract?->buyer?->party_id;
        $payerApart = $payerRow && $payerId && $payerId !== $buyerParty && ($buyerParty || $deal->payer_party_id);
        $signed = \App\Offers\Documents\SignedContractPdf::files($deal)->isNotEmpty();
        $ready = $withContract && ! \App\Offers\DealContract::for($deal)->missing();
        $priceEditable = $contract && $deal->isActive() && (($deal->moneyEditable() && $canEdit) || auth()->user()?->canManageCrm());
        // Ссылка знает свой счёт без запроса: строка берёт из него плательщика, машину и текст «Поделиться».
        $last = fn ($i, bool $live) => $i->payLinks->when($live, fn ($c) => $c->where('state', '!=', \App\Billing\Acquiring\PayLinkState::Canceled))
            ->sortByDesc('id')->first()?->setRelation('invoice', $i);
        // Поступления выписки — словом шапки; строками — только от того, кто не покупатель (`x-deal.arrivals` `except`).
        $buyerNames = array_filter([$deal->contract?->buyer?->party?->name, $deal->contract?->buyer?->name, $deal->buyer?->name]);
    @endphp
    {{-- Договор одной строкой: подписан — он, иначе готовый со скрепкой «приложить подписанный»; не готов — «Подписанный договор —
         Приложить» (подписали без нас). --}}
    @php ob_start(); @endphp
        @if ($withContract || $canEdit)
            @if ($signed && $canEdit)
                <x-deal.signed-contract :deal="$deal" :base="'/work/deals/'.$deal->id" bare doc-title="Договор"/>
            @elseif ($ready)
                <x-deal.contract-doc :deal="$deal" :attach="$canEdit"/>
            @elseif ($withContract && $canEdit && $deal->isActive())
                {{-- Не готов — строкой, как готовый: нажатие — чего не хватает, с полями (18.11.2026, `x-deal.contract-gaps-sheet`). --}}
                <x-deal.contract-gaps-sheet :deal="$deal" :attach="true"/>
            @elseif ($canEdit)
                <x-deal.signed-contract :deal="$deal" :base="'/work/deals/'.$deal->id" bare/>
            @endif
        @endif
    @php $contractLine = trim(ob_get_clean()); @endphp
    {{-- Счёт строкой документа — без суммы (она в шапке), под ним «Отправить», ссылка, «Деньги пришли?», заявки менеджера. --}}
    @php
        $billRows = function ($i, bool $withSum) use ($deal, $last, $paidBy, $payerRow, $payerId, $inStep) {
            $link = $i->state !== InvoiceState::Paid ? $last($i, true) : null;
            $linkState = $link ? \App\Http\Admin\MoneyLinks::short($link)[0] : null;
            $pdf = $i->hasPdf() ? '/work/money/invoices/'.$i->id.'/pdf' : null;

            return view('admin.deals.money-bill', compact('deal', 'i', 'link', 'linkState', 'pdf', 'paidBy', 'payerRow', 'payerId', 'inStep', 'withSum'))->render();
        };
    @endphp
    @if ($supplierBlock)
        {{-- Покупатель платит поставщику или собственнику сам (ДКП, «страховой напрямую») — блок «Поставщику» / «Собственнику». --}}
        @php ob_start(); @endphp
            @if ($withContract)<x-deal.contract :deal="$deal" bare :doc-row="false"/>@elseif ($withBuyer)<x-deal.buyer :deal="$deal"/>@endif
            @include('admin.deals.supplier-rows')
            <x-deal.contract-gaps :deal="$deal"/>
            {!! $contractLine !!}
        @php $top = trim(ob_get_clean()); @endphp
        <div class="mb-2 flex items-start gap-3 px-3">
            <span class="min-w-0 flex-1 font-medium">{{ $deal->schemeOf() === \App\Offers\DealScheme::OwnerDkp ? 'Собственнику' : 'Поставщику' }}</span>
            {{-- Гаражной суммы собственнику нет (её платит менеджер сам) — без нуля в шапке. --}}
            @if ($deal->isDirect() && $deal->owner_price === null)<span class="nums shrink-0">уточним</span>@elseif ($deal->ownerPrice() !== null)<span class="nums shrink-0">{{ Money::rub((int) $deal->ownerPrice()) }}</span>@endif
        </div>
        @if ($top !== '')<div class="list mb-3 last:mb-0">{!! $top !!}</div>@endif
    @elseif ($carBlock)
        <div class="mb-2 flex items-start gap-3 px-3">
            <span class="min-w-0 flex-1">
                <span class="block font-medium">{{ ($sale?->seller ?? $deal->seller())->config()['name'] }}</span>
                @if ($carWord)<span class="block text-sm {{ $carWord[1] }}">{{ $carWord[0] }}</span>@endif
            </span>
            @if ($priceEditable)
                <button type="button" class="shrink-0 text-right" data-controller="emit" data-action="emit#send" data-emit-event-param="contract-price-{{ $deal->id }}:open">
                    <span class="nums block font-medium">{{ $carSum ? Money::rub($carSum) : 'нет суммы' }}</span>
                    <span class="block text-sm text-ink-muted">{{ $contract->vatRate() ? 'НДС '.$contract->vatRate().' %' : 'без НДС' }}</span>
                </button>
            @else
                <span class="shrink-0 text-right">
                    <span class="nums block font-medium">{{ $carSum ? Money::rub($carSum) : 'нет суммы' }}</span>
                    @if ($contract)<span class="block text-sm text-ink-muted">{{ $contract->vatRate() ? 'НДС '.$contract->vatRate().' %' : 'без НДС' }}</span>@endif
                </span>
            @endif
        </div>
        {{-- Счёт есть — поступления покупателя сказаны словом шапки, строками — чужие плательщики; счёта нет — строкой «Пришло по
             выписке» ниже. --}}
        @if ($sale)<x-deal.arrivals :deal="$deal" :except="$buyerNames"/>@endif
        <div class="list mb-3 last:mb-0">
            @if ($withContract)<x-deal.contract :deal="$deal" bare :doc-row="false"/>@elseif ($withBuyer)<x-deal.buyer :deal="$deal"/>@endif
            @if ($payerApart)<x-deal.payer :deal="$deal" :action="'/work/deals/'.$deal->id.'/payer'" :editable="$canEdit"/>@endif
            {{-- Чего не хватает договору, говорит его строка-заготовка — отдельными строками только без неё. --}}
            @unless ($withContract && $canEdit && $deal->isActive() && ! $ready && ! $signed)<x-deal.contract-gaps :deal="$deal" :price-row="(bool) $contract"/>@endunless
            {{-- Счёт — первым, договор за ним (18.11.2026, начальник: «договор вообще после счёта должен формироваться»). Счёта нет —
                 строка-заготовка: нажатие говорит, чего не хватает, и даёт ввести (`x-deal.invoice-gaps`). --}}
            @if ($sale)
                {!! $billRows($sale, false) !!}
            @elseif ($deal->isActive())
                <x-deal.invoice-gaps :deal="$deal"/>
            @endif
            {!! $contractLine !!}
        </div>
    @else
        @php ob_start(); @endphp
            @if ($withBuyer)<x-deal.buyer :deal="$deal"/>@endif
            {!! $contractLine !!}
        @php $top = trim(ob_get_clean()); @endphp
        @if ($top !== '')<div class="list mb-3 last:mb-0">{!! $top !!}</div>@endif
    @endif
    {{-- Шторки без своих строк — их открывают сумма в шапке и пункты «⋯» (18.11.2026). --}}
    @if ($contract)<x-deal.contract-price :deal="$deal" :contract="$contract" :row="false"/>@endif
    @unless ($deal->isAuction())<x-deal.scheme :deal="$deal" bare :price="false" :row="false"/>@endunless
    @if ($payerRow && ! $payerApart)<x-deal.payer :deal="$deal" :action="'/work/deals/'.$deal->id.'/payer'" :editable="$canEdit" :row="false"/>@endif
    @if ($selection && $deal->buyer_id)<x-deal.fee-seller :deal="$deal" :row="false"/>@endif
    @if ($deal->isAuction() && $invoices->isEmpty())
        <p class="mt-3 text-sm text-ink-muted">Счёт вне системы</p>
    @endif
    {{-- Разовые оплаты по ссылке с машиной сделки (09.10.2026) и деньги выписки, что сделке класть некуда. --}}
    @php
        $links = $credits->toBase()->map(fn ($c) => [$last($c, false), $c->state === InvoiceState::Paid ? 'вручную, в зачёт сделки' : 'вручную, засчитаем'])
            // Отменённая ссылка — мёртвая строка «отменена» рядом с теми же деньгами строкой ниже (12.11.2026, владелец: «мусор»).
            ->filter(fn ($p) => $p[0] && $p[0]->state !== \App\Billing\Acquiring\PayLinkState::Canceled);
    @endphp
    @if ($links->isNotEmpty() || $extra > 0)
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
                    // Деньги по VIN из выписки, что в счёт не легли: счёта ещё нет — сходится ли с ним (`Deal::arrivedBalance`); сверх
                    // счёта — услуги подбора (18.11.2026, владелец: «все переплаты сверх закупочных — плата за услуги подбора»).
                    $bankPays = $credits->flatMap->payments->filter(fn ($p) => $p->source === \App\Billing\PaymentSource::Bank && ! $p->voided_at);
                    $bank = $bankPays->first();
                    // Когда пришло — датами в той же строке (строк поступлений над блоком у сделки без счёта нет).
                    $bankDays = $bankPays->pluck('paid_at')->filter()->sort()->map(fn ($d) => $d->format('d.m'))->unique()->implode(', ');
                    $arrived = $deal->arrivedBalance();
                    $short = $arrived && $arrived['diff'] <= -1;
                    $over = $arrived && $arrived['against'] === 'invoice' && $arrived['diff'] >= 1;
                @endphp
                <div class="row">@if ($links->isEmpty())<span class="min-w-0 flex-1"><span class="block">{{ $bank ? 'Пришло по выписке' : 'Оплачено вручную' }}@if ($bankDays !== '') <span class="text-ink-muted">{{ $bankDays }}</span>@endif</span><span class="row-sub">@if ($short)<span class="whitespace-nowrap">{{ $arrived['against'] === 'cost' ? 'до закупочной не хватает' : 'не хватает' }} <span class="nums text-danger">{{ Money::rub(abs($arrived['diff'])) }}</span></span>@elseif ($over || ($deal->agreed() && $issued->isNotEmpty()))услуги подбора@else{{ 'засчитаем, когда выставим счёт' }}@endif</span></span>@else<span class="min-w-0 flex-1 text-ink-muted">{{ $deal->agreed() && $issued->isNotEmpty() ? 'Услуги подбора' : 'Засчитаем, когда выставим счёт' }}</span>@endif<span class="nums shrink-0">{{ Money::rub($extra) }}</span></div>
            @endif
        </div>
    @endif
    {{-- Услуги подбора (ДКП, «страховой», доля гаража) — своим блоком: шапка — сумма и слово, ниже ссылка и отметки. --}}
    @if ($selection)
        @php $selWord = \App\Billing\PayWord::of($selection); @endphp
        <div class="mt-4 mb-2 flex items-start gap-3 px-3">
            <span class="min-w-0 flex-1">
                <span class="block font-medium">Услуги подбора</span>
                @if ($selWord)<span class="block text-sm {{ $selWord[1] }}">{{ $selWord[0] }}</span>@endif
            </span>
            <span class="nums shrink-0 font-medium">{{ Money::rub((float) $selection->total) }}</span>
        </div>
        @php $selRows = trim($billRows($selection, false)); @endphp
        @if ($selRows !== '')<div class="list">{!! $selRows !!}</div>@endif
    @endif
    {{-- Прочие наши счета («Ещё счёт») — строками с суммой: в шапках их нет. --}}
    @if ($otherBills->isNotEmpty())
        <div class="list mt-4">@foreach ($otherBills as $i){!! $billRows($i, true) !!}@endforeach</div>
    @endif
    {{-- Счёт на комиссию — только по просьбе (18.11.2026): «Запросили счёт…» мелко справа под блоком. --}}
    @php $paperFor = $canEdit && $deal->isActive() ? $invoices->first(fn ($i) => \App\Billing\Invoice::feeByLink($i->kind, $i->seller) && ! $i->requested_at && $i->state === InvoiceState::Issued) : null; @endphp
    @if ($paperFor)<x-billing.request-invoice :action="'/work/deals/'.$deal->id.'/invoices/'.$paperFor->id.'/paper'" staff class="mt-1"/>@endif
    {{-- Шторки «Оплатили без ссылки…» — их открывает пункт «⋯» карточки. --}}
    @foreach ($paidBy as $i)
        <div class="contents" data-controller="sheet" data-action="paid-{{ $i->id }}:open@window->sheet#open">
            <x-ui.sheet id="paid-{{ $i->id }}" :title="$i->isDocument() ? 'Оплата по счёту '.$i->label() : 'Оплата подбора'"><x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" :source="$i->isDocument() ? null : 'cash'" sheet/></x-ui.sheet>
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
