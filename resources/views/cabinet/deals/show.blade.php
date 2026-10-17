@php
    use App\Offers\CommissionState;
    use App\Offers\DealState;
    $feeState = $deal->commissionState();
    // Счета, что уже стоят в «Расчёте» (подбор, ПРАЙМ), второй раз «Счетами» ниже пути не пишем (11.10.2026: «Счёт № 1,
    // оплачен, 900 000» стоял и там, и там); аннулированные менеджеру ни о чём. Остаются прочие оплаченные — и все в гараже,
    // где «Расчёта» нет.
    $liveInvoices = $invoices->reject(fn ($i) => $i->state === \App\Billing\InvoiceState::Void);
    $inMoney = $deal->isGarage() ? [] : collect([\App\Billing\ChargeKind::Selection, \App\Billing\ChargeKind::Sale])
        ->map(fn ($kind) => $liveInvoices->first(fn ($i) => $i->kind === $kind)?->id)->filter()->all();
    $pastInvoices = $liveInvoices->reject(fn ($i) => $i->state === \App\Billing\InvoiceState::Issued || in_array($i->id, $inMoney, true));
    $docs = \App\Offers\OfferFiles::forManagers($offer, auth()->user());
    $photos = $offer->visiblePhotos();
    // Шаг договора (просьба приложить документ, у сделки есть ДКП): покупатель и сам ДКП — пунктами задачи, а не блоком
    // ниже неё (05.10.2026, владелец: «просит приложить договор, а он где-то ниже формируется»).
    // Получение по ДКП (Т-Страхование, 06.10.2026): «Связался» → «Забрал» → подписанный договор — тот же чек-лист с первого шага.
    $pickupSteps = \App\Offers\Handover::checklist($deal, $position);
    $contractStep = $deal->isActive() && $deal->hasContract() && $requirement && ($pickupSteps || ($requirement->asks === \App\Workflow\Asks::Document && ! $position?->stage->isPayStep()));
    // ПРАЙМ без покупателя: задача и есть выбор покупателя — он в ней, а не вторым блоком с той же кнопкой.
    $needsBuyer = $deal->isActive() && $position?->stage->isPayStep() && $deal->hasContract() && in_array($deal->invoiceGap(), ['buyer', 'passport'], true);
    // Каркаде, пока поставщик не согласовал заявку (11.10.2026): «Выбрать покупателя» и ДКП рано — лот ещё могут не отдать
    // (владелец 09.10: «рано спрашивать у менеджера, покупает ли»). Блок встанет после ответа поставщика.
    $tooEarly = $deal->isActive() && ! $deal->agreed() && $offer->vendor?->deal_format === \App\Vendors\DealFormat::Wholesale;
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Сделки', '/deals']">
    <x-slot:badge><x-car.keys :count="$offer->keys_count" label/></x-slot:badge>
    {{-- Сделка — не объявление (05.10.2026, владелец): первым делом задача, справа расчёт, кадры — плиткой в самом низу
         колонки. ПК: слева работа (задача, покупатель, путь, счета), справа липко расчёт, характеристики, документы, фото.
         Телефон — одна колонка: чат, задача, расчёт, покупатель, путь, характеристики, документы, фото. Чат — карточкой
         (`cabinet.deals.chat-card`): на ПК первым сбоку, на телефоне самым верхом. --}}
    <div data-deal-offer="{{ $offer->number }}" class="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-8">
        <section class="contents lg:col-start-1 lg:row-start-1 lg:flex lg:min-w-0 lg:flex-col lg:gap-6">
            <div class="order-1 min-w-0">@include('cabinet.deals.step', ['contractStep' => $contractStep, 'pickupSteps' => $pickupSteps])</div>
            {{-- Закончилась без покупателя — «выберите покупателя» уже не о чем. --}}
            @if ($deal->hasContract() && ! $contractStep && ! $needsBuyer && ! $tooEarly && ($deal->isActive() || $deal->contract?->buyer_user_id))<div class="order-3 min-w-0">@include('cabinet.deals.contract')</div>@endif
            @include('cabinet.deals.path', ['class' => 'order-5'])

            @if ($pastInvoices->isNotEmpty())
                {{-- Счета и ответы — группами строк, как в «Настройках», а не списком внутри коробки. --}}
                <section class="order-6 min-w-0">
                    <h2 class="list-head">Счета</h2>
                    <div class="list">
                        @foreach ($pastInvoices as $i)@include('cabinet.deals.invoice-row', ['invoice' => $i])@endforeach
                    </div>
                </section>
            @endif

            @php $answered = $deal->requirements->whereNotNull('done_at')->filter(fn ($r) => ! empty($r->answer['exit'])); @endphp
            @if ($answered->isNotEmpty())
                <section class="order-6 min-w-0">
                    <h2 class="list-head">Ваши ответы</h2>
                    <div class="list">
                        @foreach ($answered as $req)
                                <div class="px-4 py-3">
                                    <div class="flex items-baseline justify-between gap-3">
                                        <span class="min-w-0 break-words">{{ $req->title }}: «{{ $req->answer['exit'] }}»@if (!empty($req->answer['fields'])), {{ implode(', ', $req->answer['fields']) }}@endif</span>
                                        <span class="nums shrink-0 text-sm font-normal text-ink-dim">{{ $req->done_at->translatedFormat('j M') }}</span>
                                    </div>
                                    {{-- Приложенное остаётся видно и после ответа. --}}
                                    @foreach ($req->getMedia('files') as $media)
                                        <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}" :refs="[$offer->claim_ref]" class="mt-2"/>
                                    @endforeach
                                </div>
                        @endforeach
                    </div>
                </section>
            @endif
        </section>

        <aside class="contents lg:sticky lg:top-24 lg:col-start-2 lg:row-start-1 lg:flex lg:flex-col lg:gap-6 lg:self-start">
            {{-- Чат — как диалог мессенджера, а не кнопкой в заголовке (06.10.2026, владелец): на ПК первым в боковой
                 колонке, на телефоне — самым верхом, над задачей. --}}
            @if ($offer->chatOpenFor(auth()->user()))<div class="order-first min-w-0">@include('cabinet.deals.chat-card')</div>@endif
            <div class="order-2 min-w-0">
                @if (! $deal->isGarage())
                    @include('cabinet.deals.money')
                @else
                    <div class="box">
                        <a href="/offers/{{ $offer->number }}" class="nums block text-[32px] font-bold leading-none">{{ \App\Support\Money::rub($deal->amount) }}</a>
                        @if ($deal->state !== DealState::Active)<x-ui.state :tone="$deal->state === DealState::Done ? 'open' : 'danger'" class="mt-2">{{ mb_strtolower($deal->state->label()) }}</x-ui.state>@endif
                        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3">
                            <div class="min-w-0"><dt class="text-sm text-ink-dim">Предложение</dt><dd class="nums mt-0.5 break-words font-normal"><x-ui.copy-code :value="(string) $offer->number"/></dd></div>
                            @if ($feeState !== CommissionState::Hidden)
                                <div class="col-span-2 min-w-0"><dt class="text-sm text-ink-dim">Агентское вознаграждение</dt><dd class="mt-0.5 flex flex-wrap items-baseline justify-between gap-2"><a href="/account/money/deals/{{ $deal->id }}" class="nums font-semibold">{{ \App\Support\Money::rub($deal->commission) }}</a><x-ui.state :tone="$feeState->tone()">{{ mb_strtolower($feeState->label()) }}</x-ui.state></dd></div>
                            @endif
                        </dl>
                    </div>
                @endif
            </div>
            <x-offer.facts :offer="$offer" :full="true" class="order-7"/>
            @if ($docs->isNotEmpty())
                <section class="order-8 min-w-0">
                    <h2 class="list-head lg:pt-0">Документы</h2>
                    <div class="flex flex-col">
                        @foreach ($docs as $media)
                            <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}" :refs="[$offer->claim_ref]"/>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($photos->isNotEmpty())
                <section class="order-9 min-w-0">
                    <h2 class="list-head lg:pt-0">Фото <span class="nums">{{ $photos->count() }}</span></h2>
                    <x-offer.gallery :photos="$photos" :alt="$offer->titleWithYear()" tiles/>
                </section>
            @endif
        </aside>
    </div>
</x-ui.shell>
