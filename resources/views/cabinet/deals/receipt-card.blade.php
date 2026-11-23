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
    // Менеджер сам платит поставщику по его счёту — и «страховой напрямую», и гаражная «платит менеджер» (20.11.2026, владелец по
    // 7925/046/01102/26: «гараж — та же сделка с доп. этапом и расходами, всё остальное ровно то же»; гаражной `paysSelection` нет —
    // подбор у неё долей, — и счёт Альфы со своей платёжкой у Сергея не показывались, сумма поставщику — «уточним»).
    $toSupplier = $deal->paysSupplierSelf();
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
    $supplierStep = $live && $requirement && $toSupplier && $deal->paysSupplierOn($requirement->stage);
    $supplierFiles = $toSupplier ? \App\Offers\SupplierInvoice::shown($offer) : collect();
    $proofs = $toSupplier ? ($deal->sentPaymentProof()?->getMedia('files') ?? collect()) : collect();
    // На шаге оплаты поставщику уже приложенные, но ещё не ответившие шаг листы — тоже платёжками.
    $stepProofs = $supplierStep ? $requirement->getMedia('files') : collect();
    $carTitle = $ours ? ($sale?->seller ?? $deal->seller())->config()['name'] : ($scheme === DealScheme::Insurer ? 'Поставщику' : 'Собственнику');
    $carSum = $ours ? ($sale ? $sale->total : \App\Offers\DealContract::priceFor($deal)) : ($deal->isDirect() && $deal->owner_price === null ? null : ($toSupplier ? $deal->supplierPayment() : $deal->ownerPrice()));
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
                {{-- Состояние денег — словом подписи «Счёт» ниже (19.11.2026), в шапке только кому и сколько. --}}
                <span class="min-w-0 flex-1"><span class="block font-medium">{{ $carTitle }}</span></span>
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

            {{-- Счёт и договор — своими группами с подписью и состоянием справа (19.11.2026, владелец: «разделить на строки, подписать
                 красиво»), как «Покупатель»: «Счёт — к оплате до 03.12», «Договор — готов». Кнопки — под строкой документа и не уходят,
                 пока счёт не оплачен целиком (владелец: «вдруг он часть оплатил, а потом доприложил ещё»): приложил — «Приложить ещё». --}}
            @php
                $carPay = $ours && $sale && $mine && $sale->state === InvoiceState::Issued && $sale->remaining() > 0;
                $saleWord = $ours && ($sale || $cb) ? \App\Billing\DocWord::manager($sale, $cbReady) : null;
                $slipMore = $sale && ($salePayments->isNotEmpty() || $sale->claims()->exists());
                $saleNo = $sale ? (str_starts_with($sale->label(), '№') ? $sale->label() : '№ '.$sale->label()) : null;
            @endphp
            @if ($saleWord)
                <div>
                    <x-deal.doc-cap label="Счёт" :state="$saleWord[0]" :tone="$saleWord[1]"/>
                    @if ($sale)
                        <div class="list">
                            {{-- К оплате — строкой, что открывает «по счёту» с PDF и QR; оплачен — файлом. --}}
                            @if ($carPay)
                                <div class="contents" data-controller="sheet">
                                    <button type="button" class="row w-full text-left" data-action="sheet#open">
                                        <x-ui.file-icon name="schet.pdf"/><span class="min-w-0 flex-1 truncate">Счёт {{ $saleNo }} <span class="text-ink-muted">от {{ \App\Support\Ago::day($sale->issued_at) }}</span></span><x-ui.chevron/>
                                    </button>
                                    <x-billing.pay-sheet :id="'pay-'.$sale->id.'-transfer'" pick="transfer" only :invoices="collect([$sale])" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf" :buyers="$buyers"
                                        :open="($errors->any() && old('way') === 'transfer' && old('invoice') == $sale->id) || request()->boolean('pay') || (int) request('invoice') === $sale->id"/>
                                </div>
                            @else
                                <x-ui.file :name="'Счёт '.$saleNo" mime="application/pdf" :href="'/account/invoices/'.$sale->id.'/pdf'.$sale->pdfStamp()" download class="row"/>
                            @endif
                            {{-- Что уже внесено: платёжки, наличные, переводы. --}}
                            @foreach ($salePayments as $p)
                                <x-money.payment :payment="$p" :slip="'/account/money/invoices/'.$sale->id.'/payments/'.$p->id.'/slip'"/>
                            @endforeach
                            @if ($carPay)
                                <div class="send-under send-pair">
                                    {{-- «Приложить» одним документом (24.11.2026, `x-ui.photo-pdf`): фото и файлы — одно платёжное поручение
                                         чеком «я оплатил» на остаток (`MoneyController::payDoc`). Рядом «Наличными…» — тесно, здесь «Платёжка». --}}
                                    <x-ui.photo-pdf :id="'slip-'.$sale->id" :url="'/account/money/deals/'.$deal->id.'/pay/doc'" :fields="['invoice' => (string) $sale->id]" title="Платёжное поручение"
                                        :name="'Платёжное поручение '.$deal->offer->title()" icon="clip" always :label="$slipMore ? 'Приложить ещё' : 'Приложить платёжку'"
                                        :button="\Illuminate\Support\Arr::toCssClasses(['btn btn-s gap-2', 'btn-accent' => $left($sale) > 0, 'btn-quiet' => $left($sale) <= 0])"/>
                                    <div class="contents" data-controller="sheet">
                                        <button type="button" class="btn btn-quiet btn-s" data-action="sheet#open">Наличными…</button>
                                        <x-billing.pay-sheet :id="'pay-'.$sale->id.'-cash'" pick="cash" only :invoices="collect([$sale])" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf" :buyers="$buyers"
                                            :open="$errors->any() && old('way') === 'cash' && old('invoice') == $sale->id"/>
                                    </div>
                                </div>
                            @endif
                        </div>
                        @error('slip')<p class="field-error">{{ $message }}</p>@enderror
                    @endif
                </div>
            @endif

            {{-- Поставщику (ДКП, «страховой напрямую»): его счёт и наши платёжки. Платёжка — ответ шага, закрывает его сама; кнопка
                 не уходит — «Приложить ещё». --}}
            @if (! $ours && ($supplierFiles->isNotEmpty() || $proofs->isNotEmpty() || $stepProofs->isNotEmpty() || $supplierStep))
                <div>
                    <x-deal.doc-cap label="Счёт поставщика" :state="$carState[0] ?? null" :tone="($carState[1] ?? '') === 'text-open' ? 'ok' : 'muted'"/>
                    <div class="list">
                        @foreach ($supplierFiles as $f)
                            <x-ui.file name="Счёт поставщика" :mime="$f->mime_type" :bytes="$f->size" :size="'от '.$f->created_at->timezone('Europe/Moscow')->format('d.m')" href="/files/{{ $f->id }}" download class="row"/>
                        @endforeach
                        @foreach ($proofs->concat($stepProofs) as $m)
                            <x-ui.file name="Платёжное поручение" :mime="$m->mime_type" :bytes="$m->size" :size="\App\Support\Ago::at($m->created_at, true, words: false)" href="/files/{{ $m->id }}" class="row"/>
                        @endforeach
                        @if ($supplierStep)
                            {{-- «Приложить» одним документом (24.11.2026, `x-ui.photo-pdf`): фото и файлы — одно платёжное поручение. --}}
                            <div class="send-under">
                                <x-ui.photo-pdf :id="'supplier-proof-'.$deal->id" :url="'/deals/'.$deal->id.'/files/doc'" title="Платёжное поручение" :name="'Платёжное поручение '.$deal->offer->title()" icon="clip" always
                                    :label="$stepProofs->isEmpty() ? 'Приложить платёжное поручение' : 'Приложить ещё'"
                                    :button="\Illuminate\Support\Arr::toCssClasses(['btn btn-s w-full gap-2', 'btn-accent' => $stepProofs->isEmpty(), 'btn-quiet' => $stepProofs->isNotEmpty()])"/>
                            </div>
                        @endif
                    </div>
                    {{-- Лист уже приложен, а шаг не отвечен (приложили до 18.11) — ответ шага «Оплатил, документ приложен». --}}
                    @if ($supplierStep && $stepProofs->isNotEmpty())<div class="mt-3">@include('cabinet.deals.exits', ['exits' => $deal->managerExits($requirement->stage)])</div>@endif
                </div>
            @endif

            {{-- Договор — сразу за счётом, своей группой: «в обработке», «готов» (файл Word или PDF), «подписан». Подписанный — строкой
                 и кнопкой «Приложить подписанный» / «Приложить ещё» (на шаге договора — лаймовой). --}}
            @if ($contractRow && ($cb || $signedFiles->isNotEmpty()) && ($live || $contract->shownToManager()))
                @php
                    $shown = $contract->shownToManager() || ($contract->shown_at && \App\Offers\ContractEdit::has($deal));
                    $dealWord = match (true) {
                        $signedFiles->isNotEmpty() => ['подписан', 'ok'],
                        $shown => ['готов', 'ok'],
                        $contract->missing() && ! $cbReady => ['нужны данные', 'bad'],
                        default => ['в обработке', 'muted'],
                    };
                    $canSign = $live && \App\Offers\Actions\AttachSignedContract::allows($deal);
                @endphp
                <div>
                    <x-deal.doc-cap label="Договор" :state="$dealWord[0]" :tone="$dealWord[1]"/>
                    {{-- Строк нет (договор ещё у нас) — одна кнопка без плашки вокруг (19.11.2026, владелец: «кривая вёрстка»). --}}
                    @if (! $shown && $signedFiles->isEmpty() && $canSign)
                        <x-deal.attach-signed :deal="$deal" :base="'/deals/'.$deal->id" :primary="$signStep" wide/>
                    @elseif ($shown || $signedFiles->isNotEmpty())
                        <div class="list">
                            @if ($shown)
                                @php $dkp = new \App\Offers\DkpDocument($contract); @endphp
                                <x-deal.dkp-file :deal="$deal" :title="'Договор'.($dkp->number() ? ' № '.$dkp->number() : ' купли-продажи')" class="row"/>
                            @endif
                            @if ($signedFiles->isNotEmpty())
                                <x-deal.signed-contract :deal="$deal" :base="'/deals/'.$deal->id" bare/>
                            @elseif ($canSign)
                                <div class="send-under"><x-deal.attach-signed :deal="$deal" :base="'/deals/'.$deal->id" :primary="$signStep" wide/></div>
                            @endif
                        </div>
                        @error('files')<p class="field-error">{{ $message }}</p>@enderror
                        @error('files.*')<p class="field-error">{{ $message }}</p>@enderror
                    @endif
                </div>
            @endif
        </div>

        {{-- Подбор (ДКП, «страховой напрямую») — ООО «ПРАЙМ» ссылкой на оплату или наличными; счёта за подбор нет (по просьбе). --}}
        @if ($direct)
            @php $feePay = $payable($selection); @endphp
            <div class="deal-money-row deal-money-block flex flex-col gap-3">
                <div class="flex items-start gap-3">
                    <span class="min-w-0 flex-1"><span class="block font-medium">{{ ($selection?->seller ?? \App\Billing\Seller::Prime)->config()['name'] }}</span>@if ($s = $stateOf($selection))<span @class(['block text-sm', $s[1]])>{{ $s[0] }}</span>@endif</span>
                    @if ($selection || ! $deal->rewardPending())<span class="nums shrink-0">{{ Money::rub((int) ($selection ? ($selection->state === InvoiceState::Paid ? $selection->total : $selection->remaining()) : $deal->ours())) }}</span>@else<span class="shrink-0 text-ink-muted">уточним</span>@endif
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
