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
    $payerRow = $deal->buyer_id && $deal->moneyOpen() && ! $deal->isAuction()
        && ($deal->schemeOf()->paysCar() || $invoices->contains(fn ($i) => $i->kind === \App\Billing\ChargeKind::Sale && ! $i->isOwed()));
    $payerId = $payerRow ? ($deal->payer_party_id ?? $deal->payerUser(sale: $deal->isPrime())?->party_id) : null;
    // Разовые оплаты по ссылке с машиной сделки (09.10.2026): их деньги лежат в счетах сделки зачётом, что не легло — сверх.
    $credits = $deal->credits()->with(['party', 'offer', 'payments', 'payLinks.attempts', 'payLinks.creator', 'payLinks.payerUser'])->get();
    $extra = $credits->isNotEmpty() ? $deal->creditExtra() : 0;
    $issued = $invoices->reject(fn ($i) => $i->isOwed());
    // Скопировать ссылку на подбор — пунктом «⋯» (25.11.2026: у строки ссылки в группе «Счёт и подбор» своих кнопок нет).
    $pairBills = $issued->filter(fn ($i) => in_array($i->kind, [\App\Billing\ChargeKind::Sale, \App\Billing\ChargeKind::Selection], true) && $i->state !== InvoiceState::Void);
    $pairLink = $pairBills->count() === 2 && $pairBills->contains(fn ($i) => $i->isDocument()) ? $pairBills->first(fn ($i) => ! $i->isDocument())?->openLink() : null;
    // Этап «Проверка оплаты» сам показывает заявку менеджера с «Поступило» — здесь она только строкой, без второй пары кнопок.
    // Шаг оплаты сам несёт «Деньги пришли» (`x-route.path`) — у строки счёта её тогда нет.
    $payStep = $deal->isActive() && $offer->position()?->stage->isPayStep();
    // На «Подтверждении оплаты» страховой шаг заявку не показывает (оплату подтверждает поставщик) — кнопки здесь.
    $inStep = $deal->isActive() && $offer->position()?->stage->waits_for !== \App\Workflow\WaitsFor::Supplier && $offer->position()?->stage->exitsFor(\App\Workflow\Actor::Staff, $deal)->contains(fn ($x) => str_starts_with(mb_strtolower($x->label), 'оплата получена'));
    // Первый счёт на этапе оплаты предлагает сам шаг пути — здесь его второй раз не ставим.
@endphp
@php
    $scheme = $deal->schemeOf();
    // Договор нашего юрлица: цена продажи и НДС — шторкой «Цена и вознаграждение» (`x-deal.price-money`), сумма договора считается сама.
    $contractRow = ! $deal->isAuction() && $deal->hasContract() && $scheme->paysCar();
@endphp
{{-- «Продажа» (15.11.2026, владелец: «„Деньги“ — нейрослопово», «один блок „Продажа“, второй „Оплата поставщику“»): кто продаёт,
     договор и покупатель (`x-deal.contract bare`, `x-deal.buyer`), сумма договора, наши счета и сколько остаётся нам. Что уходит за
     машину — соседним блоком `x-deal.supplier-payment`. --}}
@php
    // Договор и покупатель — строками этого блока, в окне машины так же, как на странице (20.11.2026, владелец: «а где его
    // отправлять-то», «покупателя тоже не указать в окошке» — с 13.11 договор в окне не рисовался, покупатель — только пока
    // его данных нет; вкладка «Отправить договор» открывала окно без договора).
    $withContract = $deal->hasContract() && ! $deal->isAuction();
    $withBuyer = ! $withContract && $deal->isLive() && ($deal->collectsBuyer() || $deal->contract?->buyer_user_id);
@endphp
<x-ui.card title="Продажа" {{ $attributes->class('sale-card') }}>
    @php
        // Редкое со счетами — в «⋯» карточки (15.11.2026, владелец выбрал «счёт файлом, как документ»): «Оплатили без ссылки…»
        // (шторка оплаты ниже, событием `paid-{id}:open`), «Ещё счёт», страница счёта. С 16.11.2026 и на шаге оплаты: «Деньги пришли»
        // из шага убрано — отметка нашей оплаты здесь, строкой «Деньги пришли?» под счётом.
        $paidBy = $invoices->filter(fn ($i) => ! $i->isOwed() && $i->state === InvoiceState::Issued && $i->remaining() > 0 && auth()->user()?->canEditDeals());
        // Гаражная Совкомбанка без цены — тоже (18.11.2026): цену продажи вписывают тут же, как «Продаю» менеджера.
        $editMoney = $deal->moneyOpen() && $deal->moneyEditable() && auth()->user()?->canEditDeals() && (! $deal->isGarage() || $deal->isGarageManager() || $deal->namesBuyer());
        // Сумма поставщику или собственнику и взаимозачёт со страховой — у схем с подбором, кто правит деньги (23.11.2026 ДКП,
        // `x-deal.offset-sheet`; 24.11.2026 и «страховой напрямую», владелец: «редактировать можно всё, как в „Оплатах“»).
        $offsetEdit = $editMoney && $deal->paysSelection() && ! $deal->isGarage() && $deal->cost !== null;
        // Закупочная ПРАЙМ / Р.О.С. — полем шторки денег (25.11.2026; с 24.11.2026 была своей шторкой), пока по счетам не платили.
        $costEdit = $editMoney && ! $deal->paysSelection() && ! $deal->isGarage() && ! $deal->isAuction() && $deal->cost !== null;
        // Что раньше стояло строками («Кому», «Плательщик», «Кому» у подбора) — пунктами «⋯» (18.11.2026): шапки блоков и так это говорят.
        // «Кто платит…» — с 22.11.2026 строкой «Покупатель» (владелец: «из доп. меню убрать и вставить нормально»): в «⋯» — только
        // где строки покупателя нет.
        $schemeEdit = ! $deal->isAuction() && $deal->moneyOpen() && count(\App\Offers\DealScheme::casesFor($offer->vendor, $offer)) > 1 && $deal->moneyEditable() && auth()->user()?->canEditDeals();
        $payerEdit = $payerRow && auth()->user()?->canEditDeals() && ! \App\Offers\Actions\SetDealPayer::locked($deal);
        $feeSellerEdit = $deal->buyer_id && auth()->user()?->canEditDeals() && $deal->moneyOpen() && ! \App\Offers\Actions\SetFeeSeller::locked($deal)
            && $invoices->contains(fn ($i) => $i->kind === \App\Billing\ChargeKind::Selection && ! $i->isOwed());
        $moreMoney = $editMoney || (bool) $pairLink || $withContract || $schemeEdit || $payerEdit || $feeSellerEdit || $paidBy->contains->isDocument() || ($deal->moneyOpen() && $issued->isNotEmpty());
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
                            @if ($payerEdit && ! ($withContract || $withBuyer))<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-payer-{{ $deal->id }}:open">Кто платит…</button>@endif
                            @if ($feeSellerEdit)<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="fee-seller-{{ $deal->id }}:open">Кому платят услуги подбора…</button>@endif
                        </div>
                    @endif
                    @if ($editMoney || $withContract)
                        <div class="menu-group">
                            @if ($editMoney)<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-money-{{ $deal->id }}:open">{{ $deal->isGarageManager() ? 'Вознаграждение…' : 'Цена и вознаграждение…' }}</button>@endif
                            @if ($withContract)<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="dkp-{{ $deal->id }}:open">Данные договора…</button>@endif
                            @if ($offsetEdit)<button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="offset-{{ $deal->id }}:open">Взаимозачёт со страховой…</button>@endif
                        </div>
                    @endif
                    @if ($paidBy->contains->isDocument() || $pairLink || ($deal->moneyOpen() && $issued->isNotEmpty()))
                    <div class="menu-group">
                        {{-- Подбор — кнопкой «Оплатил наличными…» под своей ссылкой, в «⋯» его нет. --}}
                        @foreach ($paidBy->filter->isDocument() as $i)
                            <button type="button" class="menu-item w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="paid-{{ $i->id }}:open">Оплатили без ссылки{{ $paidBy->filter->isDocument()->count() > 1 ? ($i->isDocument() ? ' по счёту '.$i->label() : ' за подбор') : '' }}…</button>
                        @endforeach
                        @if ($deal->moneyOpen() && $issued->isNotEmpty())<a href="/work/invoices/new?offer={{ $offer->number }}" class="menu-item w-full">Ещё счёт</a>@endif
                        @if ($pairLink)<button type="button" class="menu-item w-full" data-controller="copy" data-copy-text-value="{{ $pairLink->url() }}" data-copy-done-value="Ссылка на подбор в буфере" data-action="copy#copy">Скопировать ссылку на подбор</button>@endif
                    </div>
                    @endif
                </x-ui.more>
            @endif
            @if ($offsetEdit)<x-deal.offset-sheet :deal="$deal"/>@endif
            {{-- Закупочная ПРАЙМ / Р.О.С. — полем шторки денег (25.11.2026, владелец: «без отдельного окна»). --}}
            @if ($editMoney)
            {{-- Шторка денег сделки — пунктом «⋯» (15.11.2026: «Изменить» в заголовке «Продажи» было непонятно, что меняет). --}}
            <div data-controller="sheet" class="contents" data-action="deal-money-{{ $deal->id }}:open@window->sheet#open">
                <x-ui.sheet id="deal-money-{{ $deal->id }}" :title="$deal->isGarageManager() ? 'Деньги сделки' : 'Цена и вознаграждение'">
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
                        {{-- Цена продажи — полем (18.11.2026, владелец: «цена продажи по итогу часто другая»; и модератору). --}}
                        <x-deal.price-money :deal="$deal" submit="Сохранить"/>
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
            if (! $skips('payer') && ! ($deal->namesBuyer() && $deal->amount !== null)) $rows[] = ['В гараж', $deal->garagePayerLine(), 'text-right', true];
            // Цена продажи гаражной Совкомбанка — в шапке «ПРАЙМ» (18.11.2026), строкой не повторяется.
        } elseif ($deal->isAuction() && ! $skips('amount')) {
            $rows[] = ['Цена на аукционе', $deal->amount === null ? 'не указана' : Money::rub($deal->amount), $dd.' font-semibold', true];
        }
        // Закупочная, разница, «страховой» — в «Оплате поставщику» (15.11.2026: раскладка из пяти строк повторяла соседние блоки).
        // Нас интересуют закупочная и услуги подбора (18.11.2026, владелец): вознаграждение менеджера — просто числом, без слов
        // о том, как он его получает; то, что остаётся нам сверх закупочной, — «Услуги подбора».
        // Вознаграждение — итогом карточки зелёным (18.11.2026, «квитанция»), не строкой расклада.
        $rewardRow = ! $deal->isAuction() && ! $deal->isDirect() && (! $deal->isGarage() || $deal->namesBuyer());
        $ours = $deal->ours() === null ? null : $deal->ours() + $deal->supplierGain();
        // Услуги подбора — счётом ниже (ДКП, «страховой», доля гаража): там же заголовком с суммой, второй раз строкой не пишем.
        $feeBilled = ($deal->paysSelection() || $deal->isGarageManager()) && $invoices->contains(fn ($i) => $i->kind === \App\Billing\ChargeKind::Selection && $i->state !== InvoiceState::Void);
        if ($deal->isGarageManager() && ! $feeBilled) {
            $rows[] = ['Услуги подбора', $deal->share !== null ? Money::rub($deal->share) : 'не вписано', $dd, true];
        } elseif (! $feeBilled && ! $deal->isGarageManager() && $deal->rewardPending()) {
            // Вознаграждение не назначено — подбора ещё нет (22.11.2026): строкой «Назначить ›» в ту же шторку, что вознаграждение.
            $rows[] = ['Услуги подбора', null, '', true];
        } elseif (! $feeBilled && ! $deal->isGarageManager() && $ours !== null) {
            $rows[] = ['Услуги подбора', Money::rub($ours), $dd.($ours < 0 ? ' text-danger' : ''), true];
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
        // Счёт за ТС и ссылка на подбор — одной группой «Счёт и подбор» под «Покупателем», одна кнопка «Отправить менеджеру» (25.11.2026,
        // владелец: «блоки со ссылкой на подбор и счётом объединить и у них обоих сделать кнопку отправить, которая и то и то отправляет»).
        $joint = $sale && $selection && $sale->isDocument() && ! $selection->isDocument() && ! in_array($deal->schemeOf(), [\App\Offers\DealScheme::Insurer, \App\Offers\DealScheme::OwnerDkp], true);
        $canEdit = auth()->user()?->canEditDeals();
        $contract = $contractRow ? \App\Offers\DealContract::for($deal) : null;
        // Сделка на шаге подписанного договора — покупатель и договор в шаге «Хода сделки» (`x-deal.contract-step`, 27.11.2026);
        // здесь не повторяются (одно действие — одно место, и шторки с одним id дважды на странице не открываются).
        $contractStep = $canEdit && $deal->contractStepNow();
        // За ТС платят нам (ПРАЙМ, Р.О.С., гараж): наш договор или счёт за машину.
        $carBlock = ! $deal->isAuction() && ! $supplierBlock && ($contract || $sale);
        // Справа в шапке блока — сколько платят нашему юрлицу: счёт или сумма договора (`DealContract::priceFor`); цена продажи —
        // своей строкой над блоками (18.11.2026, «квитанцией»).
        $carSum = $sale ? (float) $sale->total : ($contract?->price ?? ($deal->forGarageBuyer() ? null : \App\Offers\DealContract::priceFor($deal)));
        // Плательщик строкой — только когда это не покупатель по договору (иначе одно имя дважды); покупателя ещё нет — только
        // выбранный руками (по умолчанию платит сам менеджер, это не новость).
        $buyerParty = $deal->contract?->buyer?->party_id;
        $payerApart = $payerRow && $payerId && $payerId !== $buyerParty && ($buyerParty || $deal->payer_party_id);
        $signed = \App\Offers\Documents\SignedContractPdf::files($deal)->isNotEmpty();
        $ready = $withContract && \App\Offers\DealContract::for($deal)->sendable();
        $priceEditable = $editMoney && ! $deal->forGarageBuyer() && ! $deal->isGarageManager();
        // Ссылка знает свой счёт без запроса: строка берёт из него плательщика, машину и текст «Поделиться».
        $last = fn ($i, bool $live) => $i->payLinks->when($live, fn ($c) => $c->where('state', '!=', \App\Billing\Acquiring\PayLinkState::Canceled))
            ->sortByDesc('id')->first()?->setRelation('invoice', $i);
        // Поступления выписки — словом шапки; строками — только от того, кто не покупатель (`x-deal.arrivals` `except`).
        $buyerNames = array_filter([$deal->contract?->buyer?->party?->name, $deal->contract?->buyer?->name, $deal->buyer?->name]);
    @endphp
    {{-- Договор одной строкой — общий кусок с шагом договора «Хода сделки» (`x-deal.contract-line`, 27.11.2026). --}}
    @php ob_start(); @endphp
        @unless ($contractStep)
            <x-deal.contract-line :deal="$deal" :can-edit="(bool) $canEdit" :with-contract="$withContract" :signed="$signed" :ready="$ready"/>
        @endunless
    @php
        $contractLine = trim(ob_get_clean());
        // Слово подписи «Договор» (19.11.2026): подписан, отправлен, не отправлен или чего-то не хватает.
        $contractWord = \App\Offers\DealContract::word($deal, $withContract || $contract ? \App\Offers\DealContract::for($deal) : null, $signed);
    @endphp
    {{-- Счёт строкой документа — без суммы (она в шапке), под ним «Отправить», ссылка, «Деньги пришли?», заявки менеджера. --}}
    @php
        $billRows = function ($i, bool $withSum, bool $joint = false) use ($deal, $last, $paidBy, $payerRow, $payerId, $inStep) {
            $link = $i->state !== InvoiceState::Paid ? $last($i, true) : null;
            $linkState = $link ? \App\Http\Admin\MoneyLinks::short($link)[0] : null;
            $pdf = $i->hasPdf() ? '/work/money/invoices/'.$i->id.'/pdf'.$i->pdfStamp() : null;

            return view('admin.deals.money-bill', compact('deal', 'i', 'link', 'linkState', 'pdf', 'paidBy', 'payerRow', 'payerId', 'inStep', 'withSum', 'joint'))->render();
        };
    @endphp
    {{-- «Итог» — первой группой (22.11.2026, владелец по макету: «вёрстка ужасная», «все те же функции, что на странице, по деньгам»):
         цена продажи, кому за машину, вознаграждение менеджера, услуги подбора; цена и вознаграждение нажатием — «Цена и
         вознаграждение», справа в подписи — кому платит покупатель (нажатием — сменить). Ниже — кому платят, группами. --}}
    @php
        $topPrice = ($supplierBlock || $carBlock) && ! $deal->forGarageBuyer() && ! $deal->isGarageManager();
        $supplierSum = ! $supplierBlock ? null : match (true) {
            $deal->isDirect() && $deal->owner_price === null => 'уточним',
            $deal->paysSupplierSelf() && $deal->supplierPayment() !== null => Money::rub((int) $deal->supplierPayment()),
            default => null,
        };
        // Кому платит покупатель — словами (`DealScheme::short` — подпись сегмента, здесь по-человечески).
        // Подбор: строки ссылки и отметок — своей группой ниже; нечего показывать (оплачен) — его слово под «Услугами подбора».
        $selRows = $selection ? trim($billRows($selection, false)) : '';
        $selWord = $selection ? \App\Billing\PayWord::of($selection) : null;
        $schemeWord = $deal->isAuction() ? null : match ($deal->schemeOf()) {
            \App\Offers\DealScheme::Prime => 'через ПРАЙМ',
            \App\Offers\DealScheme::Ros => 'через Р.О.С.',
            \App\Offers\DealScheme::Insurer => 'страховой напрямую',
            \App\Offers\DealScheme::OwnerDkp => 'владельцу по ДКП',
        };
    @endphp
    @php
        $garageBuyer = $deal->forGarageBuyer();
        // Шкала долей цены продажи (25.11.2026, владелец выбрал из макетов вариант 1: «нужны шкалы, что куда от цены продажи»): у ПРАЙМ /
        // Р.О.С. с известными деньгами — счёт за ТС (`Deal::carPrice`), подбор ссылкой (`linkDue`), менеджеру, наличные; у «страховой
        // напрямую» и ДКП — поставщику или собственнику, менеджеру, подбор. Гараж и аукцион — прежними строками.
        $carNow = $carBlock && ! $deal->isGarage() && ! $deal->isAuction() ? $deal->carPrice() : null;
        $scale = $topPrice && $deal->amount !== null && ! $deal->isGarage() && ! $deal->isAuction()
            && ($carNow !== null || ($supplierBlock && $deal->supplierPayment() !== null));
    @endphp
    @if ($topPrice || $supplierSum || $shown || $rewardRow || $garageBuyer || ($feeBilled && $selection))
        {{-- Без своей подписи (22.11.2026, владелец: «шрифты скачут» — «Продажа» и «Итог» стояли двумя подписями подряд): список
             сразу под заголовком; числа одного вида — 15 обычным, полужирным только цена продажи. --}}
        <div class="list mb-3">
            @if ($schemeWord)
                @if ($schemeEdit)<x-deal.sale-row label="Покупатель платит" :value="$schemeWord" data-controller="emit" data-action="emit#send" data-emit-event-param="scheme-{{ $deal->id }}:open"/>@else<div class="row sale-row"><span class="sale-row-label">Покупатель платит</span><span class="sale-row-value">{{ $schemeWord }}</span><x-ui.chevron-space/></div>@endif
            @endif
            @if ($scale)
                @php
                    $pending = $deal->rewardPending();
                    $base = $carNow !== null ? (int) $deal->cost : (int) $deal->supplierPayment();
                    $over = $carNow !== null ? max(0, $carNow - $base) : 0;
                    $link = $carNow !== null ? (int) $deal->linkDue() : max(0, (int) $deal->selectionDue());
                    $cashNow = (int) $deal->contract?->cash_part;
                    $vat = $contract ? ($contract->vatRate() ? "НДС\u{00A0}".$contract->vatRate()."\u{00A0}%" : "без\u{00A0}НДС") : null;
                    $tag = $editMoney ? 'button' : 'div';
                    $payee = $deal->schemeOf() === \App\Offers\DealScheme::OwnerDkp ? 'Собственнику' : 'Поставщику';
                @endphp
                {{-- Деньги одной плашкой (25.11.2026, владелец: «убрать разделения между составными частями, это как одно кликабельное — оно
                     всё одно и то же окно открывает»; «подбор в счёте объединить с закупочной»; «закупочную серой, вознаграждение жёлтым»):
                     цена, шкала и строки «Закупочная / На счёт / По ссылке / Вознаграждение» без линий, нажатие — шторка денег. --}}
                <{{ $tag }} @if ($editMoney) type="button" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-money-{{ $deal->id }}:open" @endif class="row sale-money">
                    <span class="sale-money-line"><span class="sale-row-label">Цена продажи</span><span class="nums font-semibold">{{ Money::rub($deal->amount) }}@if ($editMoney)<x-ui.chevron class="sale-money-chev"/>@endif</span></span>
                    {{-- Вознаграждение не назначено — что не легло в счёт, ещё не поделено между ссылкой и вознаграждением: светлой долей. --}}
                    <x-deal.money-scale :parts="$pending
                        ? ['car' => $over, 'rest' => $carNow !== null ? $link : max(0, (int) $deal->amount - $base - $cashNow), 'cash' => $cashNow]
                        : ['car' => $over, 'link' => $link, 'mgr' => (int) $deal->commission, 'cash' => $cashNow]"/>
                    @if ($carNow !== null)
                        <span class="sale-money-line"><span><span class="share-dot share-dot--cost"></span>Закупочная@if ($vat) <span class="text-sm text-ink-muted">{{ $vat }}</span>@endif</span><span class="nums">{{ Money::rub($base) }}</span></span>
                        <span class="sale-money-line"><span><span class="share-dot share-dot--car"></span>По счёту <span class="text-sm text-ink-muted">{{ $deal->seller()->config()['name'] ?? $deal->schemeOf()->short() }}</span></span><span class="nums">{{ Money::rub($carNow) }}</span></span>
                    @else
                        <span class="sale-money-line"><span><span class="share-dot share-dot--cost"></span>{{ $payee }}</span><span class="nums">{{ $supplierSum ?? Money::rub($base) }}</span></span>
                    @endif
                    <span class="sale-money-line"><span><span class="share-dot share-dot--link"></span>По ссылке@if ($link > 0 && ! $pending) <span class="text-sm text-ink-muted">{{ $deal->feeSeller()->config()['name'] ?? '' }}</span>@endif</span>@if ($pending)<span class="text-ink-muted">назначим</span>@elseif ($link > 0)<span class="nums">{{ Money::rub($link) }}</span>@else<span class="text-ink-muted">нет</span>@endif</span>
                    @if ($rewardRow)
                        <span class="sale-money-line"><span><span class="share-dot share-dot--mgr"></span>Вознаграждение</span>@if ($deal->commission !== null)<span class="nums">{{ Money::rub((int) $deal->commission) }}</span>@elseif ($editMoney)<span class="text-accent-text">Назначить</span>@else<span class="text-ink-muted">назначим</span>@endif</span>
                    @endif
                    @if ($cashNow)
                        <span class="sale-money-line"><span><span class="share-dot share-dot--cash"></span>Наличными мимо счёта</span><span class="nums">{{ Money::rub($cashNow) }}</span></span>
                    @endif
                </{{ $tag }}>
            @else
            @if ($garageBuyer)
                {{-- Покупатель с гаражом (26.11.2026, владелец: «он не должен нигде видеть цену закупа»): цену продажи вводит он сам в гараже
                     или сотрудник (`SetGarageSalePrice`) вместе с его вознаграждением; счёт и договор его клиенту — цена минус его расходы и
                     вознаграждение (`Deal::holderNet`, 27.11.2026). --}}
                <div class="row sale-row"><span class="sale-row-label">Цена продажи</span>@if ($deal->amount !== null)<span class="sale-row-value nums font-semibold">{{ Money::rub($deal->amount) }}</span>@else<span class="sale-row-value text-ink-muted">не указана</span>@endif<x-ui.chevron-space/></div>
                @if ($deal->commission !== null)<div class="row sale-row"><span class="sale-row-label">Вознаграждение покупателя</span><span class="sale-row-value nums">{{ Money::rub($deal->commission) }}</span></div>@endif
                @if (($holderNet = $deal->holderNet()) !== null)<div class="row sale-row"><span class="sale-row-label">По счёту ПРАЙМ</span><span class="sale-row-value nums">{{ Money::rub($holderNet) }}</span></div>@endif
            @endif
            @if ($topPrice)
                @php $priceTag = $editMoney ? 'button' : 'div'; @endphp
                <{{ $priceTag }} @if ($editMoney) type="button" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-money-{{ $deal->id }}:open" @endif class="row sale-row">
                    <span class="sale-row-label">Цена продажи</span>
                    @if ($deal->amount !== null)<span class="sale-row-value nums font-semibold">{{ Money::rub($deal->amount) }}</span>@else<span class="sale-row-value text-danger">не указана</span>@endif
                    @if ($editMoney)<x-ui.chevron/>@else<x-ui.chevron-space/>@endif
                </{{ $priceTag }}>
            @endif
            @if ($supplierSum && $offsetEdit)
                {{-- Сумма поставщику или собственнику нажимается: шторка суммы и взаимозачёта (23.11.2026 ДКП, 24.11.2026 и
                     «страховой напрямую»). --}}
                <button type="button" class="row sale-row" data-controller="emit" data-action="emit#send" data-emit-event-param="offset-{{ $deal->id }}:open"><span class="sale-row-label">{{ $deal->schemeOf() === \App\Offers\DealScheme::OwnerDkp ? 'Собственнику' : 'Поставщику' }}</span><span class="sale-row-value nums">{{ $supplierSum }}</span><x-ui.chevron/></button>
            @elseif ($supplierSum)
                <div class="row sale-row"><span class="sale-row-label">{{ $deal->schemeOf() === \App\Offers\DealScheme::OwnerDkp ? 'Собственнику' : 'Поставщику' }}</span><span class="sale-row-value nums">{{ $supplierSum }}</span><x-ui.chevron-space/></div>
            @endif
            {{-- ПРАЙМ / Р.О.С. — закупочная («вход», 23.11.2026, начальник: «вход, продажа, комиссия XCAR, комиссия менеджера»):
                 строки «Поставщику» у них нет, а счёт за ТС — закупочная и подбор одной суммой. --}}
            @if ($topPrice && $carBlock && $deal->cost !== null && ! $skips('cost'))
                @if ($costEdit)
                    <x-deal.sale-row label="Закупочная" :value="Money::rub((int) $deal->cost)" data-controller="emit" data-action="emit#send" :data-emit-event-param="'cost-'.$deal->id.':open'"/>
                @else
                    <div class="row sale-row"><span class="sale-row-label">Закупочная</span><span class="sale-row-value nums">{{ Money::rub((int) $deal->cost) }}</span><x-ui.chevron-space/></div>
                @endif
            @endif
            @foreach ($shown as $r)
                @continue($r[0] === 'Услуги подбора')
                <div class="row sale-row"><span class="sale-row-label">{{ $r[0] }}@foreach ($r[4] ?? [] as [$subText, $subTone])<span class="block text-sm {{ $subTone }}">{{ $subText }}</span>@endforeach</span><span class="sale-row-value {{ $r[2] }}">{{ $r[1] }}</span><x-ui.chevron-space/></div>
            @endforeach
            @if ($rewardRow)
                {{-- Нажимается — «Цена и вознаграждение»; не назначено — лаймовым «Назначить» (и у закрытой сделки, `Deal::moneyOpen`). --}}
                @php $rewardTag = $editMoney ? 'button' : 'div'; @endphp
                <{{ $rewardTag }} @if ($editMoney) type="button" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-money-{{ $deal->id }}:open" @endif class="row sale-row">
                    <span class="sale-row-label">Вознаграждение</span>
                    @if ($deal->commission !== null)<span class="sale-row-value nums">{{ Money::rub((int) $deal->commission) }}</span>@elseif ($editMoney)<span class="sale-row-value text-accent-text">Назначить</span>@else<span class="sale-row-value text-ink-muted">назначим</span>@endif
                    @if ($editMoney)<x-ui.chevron/>@else<x-ui.chevron-space/>@endif
                </{{ $rewardTag }}>
            @endif
            {{-- Услуги подбора — последней и зелёным (23.11.2026, владелец: «светиться должна сумма подбора, а не вознаграждение»):
                 что остаётся нам после вознаграждения. Нажимается, как вознаграждение (22.11.2026, владелец:
                 «по сути должна быть такой же, как строка вознаграждения»): обе — от доли, шторка одна «Цена и вознаграждение». Доля
                 гаражной «платит менеджер» правится в гараже — у неё строка без стрелки. --}}
            @php
                $selEdit = $editMoney && ! $deal->isGarageManager();
                $selTag = $selEdit ? 'button' : 'div';
                $selRow = collect($shown)->first(fn ($r) => $r[0] === 'Услуги подбора');
                // Где подбор (23.11.2026, начальник: «включена в счёт или не включена»): отдельным счётом или ссылкой — или внутри
                // счёта за ТС (ПРАЙМ / Р.О.С. одной суммой).
                $selWhere = match (true) {
                    $deal->isGarageManager() || ($selRow && $selRow[1] === null) => null,
                    (bool) $selection => \App\Billing\Invoice::feeByLink($selection->kind, $selection->seller) ? 'отдельно по ссылке' : 'отдельным счётом',
                    $deal->splitsSelection() => 'отдельно по ссылке',
                    $deal->isPrime() => 'в счёте за ТС',
                    default => null,
                };
            @endphp
            @if ($selRow || ($feeBilled && $selection))
                <{{ $selTag }} @if ($selEdit) type="button" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-money-{{ $deal->id }}:open" @endif class="row sale-row">
                    <span class="sale-row-label">Услуги подбора@if ($selWhere)<span class="block text-sm text-ink-muted">{{ $selWhere }}</span>@endif</span>
                    @if ($selRow && $selRow[1] === null)
                        @if ($selEdit)<span class="sale-row-value text-accent-text">Назначить</span>@else<span class="sale-row-value text-ink-muted">назначим</span>@endif
                    @elseif ($selRow)
                        <span class="sale-row-value {{ $selRow[2] }} {{ str_contains($selRow[2], 'text-danger') ? '' : 'text-accent-text' }}">{{ $selRow[1] }}</span>
                    @else
                        <span class="sale-row-value nums text-accent-text">{{ Money::rub((float) $selection->total) }}@if ($selRows === '' && $selWord)<span class="block text-sm {{ $selWord[1] }}">{{ $selWord[0] }}</span>@endif</span>
                    @endif
                    @if ($selEdit)<x-ui.chevron/>@else<x-ui.chevron-space/>@endif
                </{{ $selTag }}>
            @endif
            @endif
        </div>
    @endif
    {{-- Услуги подбора (ДКП, «страховой», доля гаража) — своим блоком сразу под «Итогом», над «Покупателем» (23.11.2026, владелец):
         шапка — сумма и слово, ниже ссылка, отметки и поступления. --}}
    @if ($selection && $selRows !== '' && ! $joint)
        {{-- Юрлицо ссылки, как у менеджера («ООО «ПРАЙМ»»): услуги подбора — ссылкой на оплату, не счётом (18.11.2026); сумма — в «Итоге». --}}
        <div class="mb-3"><x-deal.doc-cap :label="'Подбор — '.($selection->seller ?? \App\Billing\Seller::Prime)->config()['name']" :state="$selWord[0] ?? null" :tone="str_contains($selWord[1] ?? '', 'open') ? 'ok' : (str_contains($selWord[1] ?? '', 'danger') ? 'bad' : (str_contains($selWord[1] ?? '', 'urgent') ? 'due' : 'muted'))" class="mt-4"/>
        @if ($selRows !== '')<div class="list">{!! $selRows !!}</div>@endif</div>
    @endif
    @if ($supplierBlock)
        {{-- Покупатель платит поставщику или собственнику сам (ДКП, «страховой напрямую») — блок «Поставщику» / «Собственнику». --}}
        @php ob_start(); @endphp
            @include('admin.deals.supplier-rows')
        @php $top = trim(ob_get_clean()); ob_start(); @endphp
            @unless ($contractStep)<x-deal.contract-gaps :deal="$deal"/>@endunless
            @include('admin.deals.supplier-contract-row')
            {!! $contractLine !!}
        @php $dealGroup = trim(ob_get_clean()); @endphp
        {{-- Покупатель — над счётом и договором своей группой: подпись и состояние сверху, в плашке имя (18.11.2026, владелец). --}}
        @if ($withContract && ! $contractStep)<div class="mb-3"><x-deal.contract :deal="$deal" bare :doc-row="false" capped/></div>@elseif ($withBuyer && ! $contractStep)<div class="mb-3"><x-deal.buyer :deal="$deal" capped/></div>@endif
        {{-- Счёт поставщика, платёжка — своей группой «Поставщику» / «Собственнику» (сколько — в «Итоге»), подпись — только над строками. --}}
        @if ($top !== '')
            <x-deal.doc-cap :label="$deal->schemeOf() === \App\Offers\DealScheme::OwnerDkp ? 'Собственнику' : 'Поставщику'"/>
            <div class="list mb-3 last:mb-0">{!! $top !!}</div>
        @endif
        {{-- Договор — своей группой с подписью (19.11.2026), как у ПРАЙМ. --}}
        @if ($dealGroup !== '')
            <x-deal.doc-cap label="Договор" :state="$contractWord[0] ?? null" :tone="$contractWord[1] ?? 'muted'"/>
            <div class="list mb-3 last:mb-0">{!! $dealGroup !!}</div>
        @endif
    @elseif ($carBlock)
        {{-- Кому платят за ТС — в «Итоге» («Покупатель платит — через ПРАЙМ»), что с деньгами — в подписи «Счёт»: своей подписи у блока
             нет (22.11.2026: две подписи подряд над «Покупателем», сумма повторяла «Счёт»). Сколько по счёту и договору — первой строкой
             группы «Счёт». --}}
        {{-- Счёт есть — поступления покупателя сказаны словом шапки, строками — чужие плательщики; счёта нет — строкой «Пришло по
             выписке» ниже. --}}
        @if ($sale)<x-deal.arrivals :deal="$deal" :except="$buyerNames"/>@endif
        {{-- Покупатель — над счётом и договором своей группой: подпись и состояние сверху, в плашке имя (18.11.2026, владелец). --}}
        @if ($withContract && ! $contractStep)<div class="mb-3"><x-deal.contract :deal="$deal" bare :doc-row="false" capped/></div>@elseif ($withBuyer && ! $contractStep)<div class="mb-3"><x-deal.buyer :deal="$deal" capped/></div>@endif
        {{-- Счёт и договор — своими группами с подписью и состоянием справа (19.11.2026, владелец: «как „Покупатель — данные есть“»):
             «Счёт — отправлен 19.11», «Договор — не отправлен»; кнопки — под строкой документа. Счёт — первым (18.11.2026, начальник:
             «договор вообще после счёта должен формироваться»). Счёта нет — строка-заготовка (`x-deal.invoice-gaps`). --}}
        @php
            $saleWord = $sale ? \App\Billing\DocWord::staff($sale) : null;
            ob_start();
        @endphp
            {{-- Со шкалой сумма счёта и наличные — строками долей выше (25.11.2026), здесь не повторяются. --}}
            @if ($carSum && ! $scale)
                @php $sumText = Money::rub($carSum).($contract ? ($contract->vatRate() ? ', НДС '.$contract->vatRate().' %' : ', без НДС') : ''); @endphp
                @if ($priceEditable)<x-deal.sale-row label="Счёт и договор" :value="$sumText" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-money-{{ $deal->id }}:open"/>@else<div class="row sale-row"><span class="sale-row-label">Счёт и договор</span><span class="sale-row-value nums">{{ $sumText }}</span><x-ui.chevron-space/></div>@endif
            @endif
            {{-- Часть мимо счёта — строкой, когда есть: из-за неё счёт и договор меньше цены продажи. --}}
            @if ($contract?->cash_part && ! $scale)<div class="row sale-row"><span class="sale-row-label">Наличными мимо счёта</span><span class="sale-row-value nums">{{ Money::rub($contract->cash_part) }}</span><x-ui.chevron-space/></div>@endif
            @if ($payerApart)<x-deal.payer :deal="$deal" :action="'/work/deals/'.$deal->id.'/payer'" :editable="$canEdit" pick/>@endif
            @if ($joint)
            @elseif ($sale)
                {!! $billRows($sale, false) !!}
            @elseif ($deal->isActive())
                <x-deal.invoice-gaps :deal="$deal"/>
            @endif
        @php $billGroup = trim(ob_get_clean()); ob_start(); @endphp
            {{-- Собственник по договору комиссии — всегда строкой (25.11.2026, `x-deal.owner`). --}}
            @if (\App\Offers\Actions\SetDealOwner::allows($deal) && ! $contractStep)<x-deal.owner :deal="$deal" :editable="$canEdit"/>@endif
            {{-- Чего не хватает договору, говорит его строка-заготовка — отдельными строками только без неё. --}}
            @unless (($withContract && $canEdit && $deal->isActive() && ! $ready && ! $signed) || $contractStep)<x-deal.contract-gaps :deal="$deal" :price-row="(bool) $contract"/>@endunless
            {!! $contractLine !!}
        @php $dealGroup = trim(ob_get_clean()); @endphp
        @if ($joint)
            @include('admin.deals.money-pair')
        @endif
        @if ($billGroup !== '')
            {{-- У группы «Счёт и подбор» плательщик (не покупатель) — своей плашкой без подписи. --}}
            @unless ($joint)<x-deal.doc-cap label="Счёт" :state="$saleWord[0] ?? null" :tone="$saleWord[1] ?? 'muted'"/>@endunless
            <div class="list mb-3 last:mb-0">{!! $billGroup !!}</div>
        @endif
        @if ($dealGroup !== '')
            <x-deal.doc-cap label="Договор" :state="$contractWord[0] ?? null" :tone="$contractWord[1] ?? 'muted'"/>
            <div class="list mb-3 last:mb-0">{!! $dealGroup !!}</div>
        @endif
    @else
        @php ob_start(); @endphp
            @if ($withBuyer)<x-deal.buyer :deal="$deal"/>@endif
            {!! $contractLine !!}
        @php $top = trim(ob_get_clean()); @endphp
        @if ($top !== '')<div class="list mb-3 last:mb-0">{!! $top !!}</div>@endif
    @endif
    {{-- Шторки без своих строк — их открывают сумма в шапке и пункты «⋯» (18.11.2026). --}}
    @unless ($deal->isAuction())<x-deal.scheme :deal="$deal" bare :price="false" :row="false"/>@endunless
    @if ($payerRow && ! $payerApart)<x-deal.payer :deal="$deal" :action="'/work/deals/'.$deal->id.'/payer'" :editable="$canEdit" :row="false" pick/>@endif
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
    {{-- Прочие наши счета («Ещё счёт») — строками с суммой: в шапках их нет. --}}
    @if ($otherBills->isNotEmpty())
        <div class="list mt-4">@foreach ($otherBills as $i){!! $billRows($i, true) !!}@endforeach</div>
    @endif
    {{-- Счёт на комиссию — только по просьбе (18.11.2026): «Запросили счёт…» мелко справа под блоком. --}}
    @php $paperFor = $canEdit && $deal->moneyOpen() ? $invoices->first(fn ($i) => \App\Billing\Invoice::feeByLink($i->kind, $i->seller) && ! $i->requested_at && $i->state === InvoiceState::Issued) : null; @endphp
    @if ($paperFor)<x-billing.request-invoice :action="'/work/deals/'.$deal->id.'/invoices/'.$paperFor->id.'/paper'" staff class="mt-1"/>@endif
    {{-- Шторки «Оплатили без ссылки…» — их открывает пункт «⋯» карточки. --}}
    @foreach ($paidBy as $i)
        <div class="contents" data-controller="sheet" data-action="paid-{{ $i->id }}:open@window->sheet#open">
            <x-ui.sheet id="paid-{{ $i->id }}" :title="$i->isDocument() ? 'Оплата по счёту '.$i->label() : 'Оплатил наличными'">
                @if ($i->isDocument())
                    <x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" sheet/>
                @else
                    <x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" :sources="['cash' => 'Наличные']" source="cash" sheet/>
                @endif
            </x-ui.sheet>
        </div>
    @endforeach
</x-ui.card>
