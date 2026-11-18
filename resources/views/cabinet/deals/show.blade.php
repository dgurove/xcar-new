@php
    use App\Offers\DealState;
    // Счета, что уже стоят в «Расчёте» (подбор, ПРАЙМ), второй раз «Счетами» ниже пути не пишем (11.10.2026: «Счёт № 1,
    // оплачен, 900 000» стоял и там, и там); аннулированные менеджеру ни о чём. Остаются прочие оплаченные — и все в гараже,
    // где «Расчёта» нет.
    $liveInvoices = $invoices->reject(fn ($i) => $i->state === \App\Billing\InvoiceState::Void);
    $inMoney = collect([\App\Billing\ChargeKind::Selection, \App\Billing\ChargeKind::Sale])
        ->map(fn ($kind) => $liveInvoices->first(fn ($i) => $i->kind === $kind)?->id)->filter()->all();
    $pastInvoices = $liveInvoices->reject(fn ($i) => $i->state === \App\Billing\InvoiceState::Issued || in_array($i->id, $inMoney, true));
    $docs = \App\Offers\OfferFiles::forManagers($offer, auth()->user());
    $photos = $offer->visiblePhotos();
    // Страница — шаг (чей ход и то, что не про деньги: «Беру в работу», получение, подписание) и под ним ОДИН блок денег и
    // документов (`cabinet.deals.receipt-card`, 18.11.2026, владелец: «везде один нормальный блок», «счёт и договор вместе»).
    // Чек-листа договора, карточек «Договор и счёт», «Договор уже подписан?», «Укажите покупателя» и денег справа нет.
    // «Автомобиль» своей карточкой под шагом (11.11.2026): получение — не само дело шага, а показать есть что — где он, кто
    // везёт, «Забрал» / «Вывоз».
    $carCard = $deal->isLive() && $handover && ! ($requirement && $position?->stage->isPickupStep())
        && ((($handover->shows() || ($handover->park && $deal->releasable())) && ! $handover->withBuyer) || $handover->carrier || $handover->canRequest);
@endphp
<x-ui.shell :title="$offer->titleWithYear()" copy-title :back="['Сделки', '/deals']">
    <x-slot:badge><x-car.keys :count="$offer->keys_count" label/></x-slot:badge>
    {{-- Сделка — не объявление (05.10.2026, владелец): первым делом задача, справа расчёт, кадры — плиткой в самом низу
         колонки. ПК: слева работа (задача, покупатель, путь, счета), справа липко расчёт, характеристики, документы, фото.
         Телефон — одна колонка: задача, чат, расчёт, покупатель, путь, характеристики, документы, фото. Чат — карточкой
         (`cabinet.deals.chat-card`): на ПК первым сбоку, на телефоне под задачей (10.11.2026). --}}
    <div data-deal-offer="{{ $offer->number }}" class="grid grid-cols-[minmax(0,1fr)] gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-6">
        <section class="contents lg:col-start-1 lg:row-start-1 lg:flex lg:min-w-0 lg:flex-col lg:gap-4">
            <x-offer.reprice-ask :deal="$deal" class="order-1 min-w-0"/>
            {{-- Доп. просьба поставщику держит сделку (18.11.2026, `Offers\ExtraAsk`) — её задача вместо шага. --}}
            @if ($extraAsk = \App\Offers\ExtraAsk::holding($deal))
                <div class="order-1 min-w-0">@include('cabinet.deals.extra-ask', ['ask' => $extraAsk])</div>
            @else
                <div class="order-1 min-w-0">@include('cabinet.deals.step', ['carCard' => $carCard])</div>
            @endif
            @if ($carCard)<div class="order-1 min-w-0">@include('cabinet.deals.car-card')</div>@endif
            <div class="order-1 min-w-0">@include('cabinet.deals.receipt-card')</div>
            @if ($deal->buyer_asked_at && $deal->isLive())<x-deal.buyer-ask-sheet :deal="$deal"/>@endif
            @include('cabinet.deals.path', ['class' => 'order-5'])

            @if ($pastInvoices->isNotEmpty())
                {{-- Счета и ответы — группами строк, как в «Настройках», а не списком внутри коробки. --}}
                <section class="order-6 min-w-0">
                    <h2 class="mb-2 px-1 text-sm font-medium text-ink-dim">Счета</h2>
                    <div class="list">
                        @foreach ($pastInvoices as $i)@include('cabinet.deals.invoice-row', ['invoice' => $i])@endforeach
                    </div>
                </section>
            @endif

        </section>

        <aside class="contents lg:sticky lg:top-[calc(var(--stick-top)+1rem)] lg:col-start-2 lg:row-start-1 lg:flex lg:flex-col lg:gap-4 lg:self-start">
            {{-- Чат — как диалог мессенджера, а не кнопкой в заголовке (06.10.2026, владелец): на ПК первым в боковой
                 колонке, на телефоне — самым верхом, над задачей. --}}
            {{-- На телефоне — под задачей (10.11.2026, владелец: «пишут в чат, а не кнопку жмут»: чат стоял над делом). --}}
            @if ($offer->chatOpenFor(auth()->user()))<div class="order-1 min-w-0">@include('cabinet.deals.chat-card')</div>@endif
            <x-offer.my-note :offer="$offer" class="order-3 min-w-0"/>
            <x-offer.facts :offer="$offer" :full="true" class="order-7"/>
            @if ($docs->isNotEmpty())
                <section class="order-8 min-w-0">
                    <h2 class="mb-2 px-1 text-sm font-medium text-ink-dim">Документы</h2>
                    <div class="flex flex-col">
                        @foreach ($docs as $media)
                            <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}" :refs="[$offer->claim_ref]"/>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($photos->isNotEmpty())
                <section class="order-9 min-w-0">
                    <h2 class="mb-2 px-1 text-sm font-medium text-ink-dim">Фото <span class="nums">{{ $photos->count() }}</span></h2>
                    <x-offer.gallery :photos="$photos" :alt="$offer->titleWithYear()" tiles :offer="$offer"/>
                </section>
            @endif
        </aside>
    </div>
</x-ui.shell>
