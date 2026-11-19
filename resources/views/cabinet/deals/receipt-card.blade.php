{{-- Деньги и документы сделки менеджеру — ОДИН блок у всех схем и на всех шагах (18.11.2026, владелец: «надо везде блок нормальный
     с суммами, счётом, договором, покупателем и ссылкой на оплату подбора»; «всегда СНАЧАЛА счёт, потом договор, и лежать они
     должны ВМЕСТЕ»; «по ссылке за машину НИКОГДА не платят — только по счёту или наличными; по ссылке только подбор»). Стоит
     сразу под шагом (`cabinet.deals.show`, гараж — `garage.cars.show`); чек-листа договора, карточек «Договор и счёт»,
     «Договор уже подписан?», «Укажите покупателя» и денег справа больше нет — всё здесь, порядком CRM («Продажа»):
     цена продажи → кому за ТС (покупатель → счёт → договор → оплаты; «Приложить платёжку», «Оплатил наличными…») →
     подбор ООО «ПРАЙМ» ссылкой → вознаграждение. Дело шага менеджера — главной кнопкой здесь же: платёжка, подписанный договор.
     Вход: $deal, $offer, $invoices, $requirement, $position. --}}
@php
    use App\Support\Money;
    use App\Billing\ChargeKind;
    use App\Billing\InvoiceState;
    use App\Offers\DealScheme;
    $me = auth()->user();
    $mine = $deal->buyer_id === $me->id;
    $live = $deal->isLive();
    $scheme = $deal->schemeOf();
    $ours = in_array($scheme, [DealScheme::Prime, DealScheme::Ros], true);
    $direct = $deal->paysSelection();
    $alive = $invoices->reject(fn ($i) => $i->state === InvoiceState::Void);
    $sale = $alive->first(fn ($i) => $i->kind === ChargeKind::Sale && $i->isShownToManager());
    $selection = $alive->first(fn ($i) => $i->kind === ChargeKind::Selection);
    $contract = \App\Offers\DealContract::for($deal)->loadMissing(['buyer.party']);
    $cb = $contract->buyer;
    $cbReady = (bool) $cb?->party?->readyAsBuyer();
    // Покупатель — с согласия или когда покупка согласована (`Deal::buyerOpen`); до того его не спрашиваем.
    $buyerShown = $cb || ($live && ($deal->buyer_asked_at || $deal->buyerOpen()) && ($deal->hasContract() || $deal->needsBuyer()));
    $pickHere = $live && $mine && ! $me->isSuperBuyer() && (! $cb || $cb->isBuyerDraft());
    $left = fn ($i) => $i ? round($i->remaining() - $i->claimed(), 2) : 0;
    $payable = fn ($i) => $i && $mine && $i->state === InvoiceState::Issued && $left($i) > 0;
    // Состояние счёта словом справа от строки «кому» (сумма — числом рядом): оплачено, осталось, ждём подтверждения.
    $stateOf = function ($i) {
        if (! $i) return null;
        return match (true) {
            $i->state === InvoiceState::Paid => ['оплачено '.\App\Support\Ago::day($i->paid_at ?? $i->updated_at), 'text-open'],
            $i->claimed() > 0 && round($i->remaining() - $i->claimed(), 2) <= 0 => ['ждём подтверждения', 'text-ink-muted'],
            $i->remaining() < $i->total => ['осталось '.Money::rub($i->remaining()), 'text-ink-muted'],
            default => null,
        };
    };
    $buyers = \App\Support\People::buyersOf($me);
    // Шаг договора: подписанный — главной кнопкой (приложенный закрывает шаг сам, `MarkContractSigned`).
    $signStep = $live && $requirement?->asks === \App\Workflow\Asks::Document && $requirement->stage?->aboutContract() && ! $requirement->stage->isPayStep();
    $signedFiles = \App\Offers\Documents\SignedContractPdf::files($deal);
    // Альфа (ПРАЙМ / Р.О.С.): договор — после подтверждённой оплаты, до неё строки договора нет.
    $contractRow = $deal->hasContract() && ! $deal->contractAfterPay();
    // Платёж поставщику на его шаге (Альфа «напрямую»): платёжка — ответ шага, закрывает его сама.
    $supplierStep = $live && $requirement && $direct && $deal->paysSupplierOn($requirement->stage);
    $supplierFiles = $direct ? \App\Offers\SupplierInvoice::shown($offer) : collect();
    $proofs = $direct ? ($deal->sentPaymentProof()?->getMedia('files') ?? collect()) : collect();
    // На шаге оплаты поставщику уже приложенные, но ещё не ответившие шаг листы — тоже платёжками.
    $stepProofs = $supplierStep ? $requirement->getMedia('files') : collect();
    $carTitle = $ours ? ($sale?->seller ?? $deal->seller())->config()['name'] : ($scheme === DealScheme::Insurer ? 'Поставщику' : 'Собственнику');
    $carSum = $ours ? ($sale ? $sale->total : \App\Offers\DealContract::priceFor($deal)) : ($deal->isDirect() && $deal->owner_price === null ? null : $deal->ownerPrice());
    $carState = $ours ? $stateOf($sale) : ($deal->prepaid_at ? ['оплачено', 'text-open'] : ($proofs->isNotEmpty() ? ['ждём подтверждения', 'text-ink-muted'] : null));
    $salePayments = $sale && $sale->relationLoaded('allPayments') ? $sale->allPayments->reject(fn ($p) => $p->source === \App\Billing\PaymentSource::Offset) : ($sale?->allPayments()->get()->reject(fn ($p) => $p->source === \App\Billing\PaymentSource::Offset) ?? collect());
@endphp
<div class="box deal-money-card" id="money">
    <div class="deal-money-rows">
        @if ($deal->amount !== null)
            <div class="deal-money-row deal-money-price flex items-start gap-3"><span class="min-w-0 flex-1">Цена продажи</span><span class="nums shrink-0 text-lg font-semibold">{{ Money::rub($deal->amount) }}</span></div>
        @endif
        @if ($direct && $deal->offset() && ! $deal->isDirect())
            <div class="deal-money-row flex items-start gap-3"><span class="min-w-0 flex-1">Взаимозачёт со страховой</span><span class="nums shrink-0 text-ink-muted">−{{ Money::rub($deal->offset()) }}</span></div>
        @endif

        {{-- За ТС: кому, сумма, состояние; ниже покупатель, счёт, договор, оплаты и кнопки — одной группой. --}}
        <div class="deal-money-row deal-money-block flex flex-col gap-3">
            <div class="flex items-start gap-3">
                <span class="min-w-0 flex-1"><span class="block font-medium">{{ $carTitle }}</span>@if ($carState)<span @class(['block text-sm', $carState[1]])>{{ $carState[0] }}</span>@endif</span>
                <span class="nums shrink-0">{{ $carSum !== null ? Money::rub((int) $carSum) : 'уточним' }}</span>
            </div>

            @if ($buyerShown)
                <div class="deal-money-buyer">
                    @if ($pickHere)
                        <div class="list-cap !px-0">Покупатель</div>
                        @include('cabinet.deals.buyer-picker')
                    @else
                        @include('cabinet.deals.contract', ['part' => 'buyer', 'embedded' => true, 'labels' => true, 'capped' => true, 'after' => ''])
                    @endif
                </div>
            @endif

            @php
                $carPay = $ours && $payable($sale);
                $docs = $sale || ($ours && $cb) || $supplierFiles->isNotEmpty() || $proofs->isNotEmpty() || $stepProofs->isNotEmpty() || ($contractRow && $cb) || $signedFiles->isNotEmpty() || $salePayments->isNotEmpty();
            @endphp
            @if ($docs)
                <div class="list">
                    {{-- Счёт — первым. Наш (ПРАЙМ / Р.О.С.): пока к оплате — строкой, что открывает «по счёту» с PDF и QR; оплачен —
                         файлом. Без счёта у ДКП собственнику — строки нет. --}}
                    @if ($sale && $carPay)
                        <div class="contents" data-controller="sheet">
                            <button type="button" class="row w-full text-left" data-action="sheet#open">
                                <x-ui.file-icon name="schet.pdf"/><span class="min-w-0 flex-1 truncate">Счёт {{ str_starts_with($sale->label(), '№') ? $sale->label() : '№ '.$sale->label() }}</span><x-ui.chevron/>
                            </button>
                            <x-billing.pay-sheet :id="'pay-'.$sale->id.'-transfer'" pick="transfer" only :invoices="collect([$sale])" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf" :buyers="$buyers"
                                :open="($errors->any() && old('way') === 'transfer' && old('invoice') == $sale->id) || request()->boolean('pay') || (int) request('invoice') === $sale->id"/>
                        </div>
                    @elseif ($sale)
                        <x-ui.file :name="'Счёт '.(str_starts_with($sale->label(), '№') ? $sale->label() : '№ '.$sale->label())" mime="application/pdf" :href="'/account/invoices/'.$sale->id.'/pdf'" download class="row"/>
                    @elseif ($ours && $cb)
                        <div class="row sale-row"><span class="sale-row-label">Счёт</span>{{-- «Ждём данные» — двусмысленно (18.11.2026, владелец): данных нет — его дело красным, есть — наш ход. --}}<span @class(['sale-row-value', 'text-ink-muted' => $cbReady, 'text-danger' => ! $cbReady])>{{ $cbReady ? 'в обработке' : 'нужны данные' }}</span></div>
                    @endif
                    @foreach ($supplierFiles as $f)
                        <x-ui.file name="Счёт поставщика" :mime="$f->mime_type" :bytes="$f->size" :size="'от '.$f->created_at->timezone('Europe/Moscow')->format('d.m')" href="/files/{{ $f->id }}" download class="row"/>
                    @endforeach
                    {{-- Договор — сразу за счётом. --}}
                    @if ($contractRow && ($cb || $signedFiles->isNotEmpty()) && ($live || $contract->shownToManager()))
                        @if ($contract->missing())
                            <div class="row sale-row"><span class="sale-row-label">Договор</span>{{-- «Ждём данные» — двусмысленно (18.11.2026, владелец): данных нет — его дело красным, есть — наш ход. --}}<span @class(['sale-row-value', 'text-ink-muted' => $cbReady, 'text-danger' => ! $cbReady])>{{ $cbReady ? 'в обработке' : 'нужны данные' }}</span></div>
                        @elseif (! $contract->shownToManager())
                            <div class="row sale-row"><span class="sale-row-label">Договор</span><span class="sale-row-value text-ink-muted">в обработке</span></div>
                        @else
                            @php $dkp = new \App\Offers\DkpDocument($contract); @endphp
                            <x-deal.dkp-file :deal="$deal" :title="'Договор купли-продажи'.($dkp->number() ? ' № '.$dkp->number() : '')" class="row"/>
                        @endif
                    @endif
                    {{-- Подписанный — файлом с «Добавить лист»; пока его нет и это не шаг договора — тихой строкой «Приложить». --}}
                    @if ($signedFiles->isNotEmpty() || (! $signStep && $contractRow && \App\Offers\Actions\AttachSignedContract::allows($deal) && $live))
                        <x-deal.signed-contract :deal="$deal" :base="'/deals/'.$deal->id" bare/>
                    @endif
                    {{-- Что уже внесено за ТС: платёжки, наличные, переводы. --}}
                    @foreach ($proofs->concat($stepProofs) as $m)
                        <x-ui.file name="Платёжка" :mime="$m->mime_type" :bytes="$m->size" :size="\App\Support\Ago::at($m->created_at, true, words: false)" href="/files/{{ $m->id }}" class="row"/>
                    @endforeach
                    @foreach ($salePayments as $p)
                        <x-money.payment :payment="$p" :slip="'/account/money/invoices/'.$sale->id.'/payments/'.$p->id.'/slip'"/>
                    @endforeach
                </div>
            @endif

            {{-- Дело менеджера по ТС — кнопкой под группой: платёжка (и «Оплатил наличными…»), подписанный договор. --}}
            @if ($carPay)
                <div class="flex flex-col gap-2">
                    <form method="post" action="/account/money/deals/{{ $deal->id }}/pay" enctype="multipart/form-data" data-controller="autosubmit">
                        @csrf
                        <input type="hidden" name="way" value="transfer"><input type="hidden" name="invoice" value="{{ $sale->id }}">
                        <label class="btn btn-accent btn-block cursor-pointer"><x-ui.icon name="clip" class="size-5"/> Приложить платёжку
                            <input type="file" name="slip" accept="image/*,.pdf,.heic" class="sr-only" tabindex="-1" data-action="change->autosubmit#submit">
                        </label>
                    </form>
                    @error('slip')<p class="field-error">{{ $message }}</p>@enderror
                    <div class="contents" data-controller="sheet">
                        <button type="button" class="btn btn-quiet btn-block" data-action="sheet#open">Оплатил наличными…</button>
                        <x-billing.pay-sheet :id="'pay-'.$sale->id.'-cash'" pick="cash" only :invoices="collect([$sale])" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf" :buyers="$buyers"
                            :open="$errors->any() && old('way') === 'cash' && old('invoice') == $sale->id"/>
                    </div>
                </div>
            @elseif ($supplierStep)
                {{-- Платёжка поставщику — тем же видом, что у нашего счёта: лаймовая кнопка; лист закрывает шаг сам (`DealController::upload`). --}}
                {{-- Лист уже приложен, а шаг не отвечен (приложили до 18.11) — только «Оплатил, документ приложен», без второй кнопки
                     (18.11.2026, владелец: «прикладывание платёжки странно выглядит»): файл — строкой «Платёжка» в группе выше. --}}
                @if ($stepProofs->isEmpty())
                <div data-controller="photos" data-photos-url-value="/deals/{{ $deal->id }}/files" data-photos-reload-value="true">
                    <input type="file" accept="image/*,.pdf,.heic" multiple class="sr-only" tabindex="-1" data-photos-target="input" data-action="change->photos#upload">
                    <div hidden data-photos-target="progress" class="mb-2">
                        <div class="mb-1 text-sm text-ink-muted" data-label></div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
                    </div>
                    <button type="button" class="btn btn-accent btn-block" data-action="photos#pick"><x-ui.icon name="clip" class="size-5"/> Приложить платёжку</button>
                </div>
                @else
                <div class="-mt-5">@include('cabinet.deals.exits', ['exits' => $deal->managerExits($requirement->stage)])</div>
                @endif
            @endif
            @if ($signStep && $signedFiles->isEmpty() && \App\Offers\Actions\AttachSignedContract::allows($deal))
                <form method="post" action="/deals/{{ $deal->id }}/signed" enctype="multipart/form-data" data-controller="autosubmit">
                    @csrf
                    <label class="btn btn-accent btn-block cursor-pointer"><x-ui.icon name="clip" class="size-5"/> Приложить подписанный договор
                        <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.doc,.docx" class="sr-only" data-action="change->autosubmit#submit">
                    </label>
                </form>
                @error('files')<p class="field-error">{{ $message }}</p>@enderror
                @error('files.*')<p class="field-error">{{ $message }}</p>@enderror
            @endif
        </div>

        {{-- Подбор (ДКП, «страховой напрямую») — ООО «ПРАЙМ» ссылкой на оплату или наличными; счёта за подбор нет (по просьбе). --}}
        @if ($direct)
            @php $feePay = $payable($selection); @endphp
            <div class="deal-money-row deal-money-block flex flex-col gap-3">
                <div class="flex items-start gap-3">
                    <span class="min-w-0 flex-1"><span class="block font-medium">{{ ($selection?->seller ?? \App\Billing\Seller::Prime)->config()['name'] }}</span>@if ($s = $stateOf($selection))<span @class(['block text-sm', $s[1]])>{{ $s[0] }}</span>@endif</span>
                    <span class="nums shrink-0">{{ Money::rub((int) ($selection ? ($selection->state === InvoiceState::Paid ? $selection->total : $selection->remaining()) : $deal->ours())) }}</span>
                </div>
                @if ($feePay)
                    @php $online = app(\App\Billing\Acquiring\Gateway::class)->configured() && \App\Billing\Acquiring\PayLink::eligible($selection); @endphp
                    @if ($online)
                        <div class="list">
                            <div class="contents" data-controller="sheet">
                                <div class="row">
                                    <x-ui.icon name="link" class="size-5 shrink-0 text-ink-dim"/>
                                    <span class="min-w-0 flex-1">Ссылка на оплату</span>
                                    <button type="button" class="btn btn-accent btn-xs shrink-0" data-action="sheet#open">Оплатить</button>
                                </div>
                                <x-billing.pay-sheet :id="'pay-'.$selection->id.'-link'" pick="link" only :invoices="collect([$selection])" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf" :buyers="$buyers"
                                    :open="session('open-link') === $selection->openLink()?->id && $selection->openLink() !== null"/>
                            </div>
                        </div>
                    @endif
                    <div class="contents" data-controller="sheet">
                        <button type="button" class="btn btn-quiet btn-block" data-action="sheet#open">Оплатил наличными…</button>
                        <x-billing.pay-sheet :id="'pay-'.$selection->id.'-cash'" pick="cash" only :invoices="collect([$selection])" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf" :buyers="$buyers"
                            :open="$errors->any() && old('way') === 'cash' && old('invoice') == $selection->id"/>
                    </div>
                    @if (\App\Billing\Invoice::feeByLink($selection->kind, $selection->seller) && ! $selection->requested_at && $selection->deal_id === $deal->id)
                        <x-billing.request-invoice :action="'/deals/'.$deal->id.'/invoices/'.$selection->id.'/paper'"/>
                    @endif
                @elseif ($selection && $selection->allPayments()->exists())
                    <div class="list">
                        @foreach ($selection->allPayments()->get()->reject(fn ($p) => $p->source === \App\Billing\PaymentSource::Offset) as $p)
                            <x-money.payment :payment="$p" :slip="'/account/money/invoices/'.$selection->id.'/payments/'.$p->id.'/slip'"/>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        @include('cabinet.deals.money-reward')
    </div>
</div>
