@php
    use App\Offers\CommissionState;
    use App\Offers\DealState;
    $feeState = $deal->commissionState();
    // Ждущие оплаты счета — в карточке шага (`cabinet.deals.step`), оплаченные и аннулированные — отдельной карточкой ниже пути.
    $pastInvoices = $invoices->reject(fn ($i) => $i->state === \App\Billing\InvoiceState::Issued);
@endphp
<x-ui.cabinet :title="$offer->titleWithYear()" :back="['Сделки', '/deals']">
    {{-- Страница ТС, как в гараже (`x-offer.object`): кадры листаются, характеристики с полным VIN, документы, открытые
         менеджеру; справа сверху — сумма, номера и вознаграждение; под кадрами — шаг сделки, счета и ответы. --}}
    <div data-deal-offer="{{ $offer->number }}">
    <x-offer.object :offer="$offer" :photos="$offer->visiblePhotos()" :docs="\App\Offers\OfferFiles::forManagers($offer, auth()->user())">
        <x-slot:aside>
            @if (! $deal->isGarage())
                @include('cabinet.deals.money')
            @else
            <div class="box">
                <a href="/offers/{{ $offer->number }}" class="nums block text-[32px] font-bold leading-none">{{ \App\Support\Money::rub($deal->amount) }}</a>
                @if ($deal->state !== DealState::Active)<x-ui.state :tone="$deal->state === DealState::Done ? 'open' : 'danger'" class="mt-2">{{ mb_strtolower($deal->state->label()) }}</x-ui.state>@endif
                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3">
                    @foreach (['Предложение' => $offer->number, 'ДЛ' => $offer->leaseRef()] as $label => $value)
                        @if ($value)<div class="min-w-0"><dt class="text-sm text-ink-dim">{{ $label }}</dt><dd class="nums mt-0.5 break-words font-normal"><x-ui.copy-code :value="(string) $value"/></dd></div>@endif
                    @endforeach
                    {{-- Вознаграждение открывается со счёта (до него менеджер видит только цену) — фактом карточки, ссылкой на расчёт. --}}
                    @if ($feeState !== CommissionState::Hidden)
                        <div class="col-span-2 min-w-0"><dt class="text-sm text-ink-dim">Агентское вознаграждение</dt><dd class="mt-0.5 flex flex-wrap items-baseline justify-between gap-2"><a href="/account/money/deals/{{ $deal->id }}" class="nums font-semibold">{{ \App\Support\Money::rub($deal->commission) }}</a><x-ui.state :tone="$feeState->tone()">{{ mb_strtolower($feeState->label()) }}</x-ui.state></dd></div>
                    @endif
                </dl>
            </div>
            @endif
            @if ($offer->chatOpenFor(auth()->user()))
                <a href="/account/chats/offer/{{ $offer->number }}" class="btn btn-quiet w-full"><x-ui.icon name="chat" class="size-5"/> Написать по сделке</a>
            @endif
        </x-slot:aside>

        @include('cabinet.deals.step')

        @if ($deal->hasContract())@include('cabinet.deals.contract')@endif

        @if ($pastInvoices->isNotEmpty())
            {{-- Счета и ответы — группами строк, как в «Настройках», а не списком внутри коробки. --}}
            <section>
                <h2 class="list-head">Счета</h2>
                <div class="list">
                    @foreach ($pastInvoices as $i)@include('cabinet.deals.invoice-row', ['invoice' => $i])@endforeach
                </div>
            </section>
        @endif

        @if ($deal->requirements->whereNotNull('done_at')->isNotEmpty())
            <section>
                <h2 class="list-head">Ваши ответы</h2>
                <div class="list">
                    @foreach ($deal->requirements->whereNotNull('done_at') as $req)
                        @if (!empty($req->answer['exit']))
                            <div class="px-4 py-3">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="min-w-0 break-words">{{ $req->title }}: «{{ $req->answer['exit'] }}»@if (!empty($req->answer['fields'])), {{ implode(', ', $req->answer['fields']) }}@endif</span>
                                    <span class="nums shrink-0 text-sm font-normal text-ink-dim">{{ $req->done_at->translatedFormat('j M') }}</span>
                                </div>
                                {{-- Приложенное остаётся видно и после ответа. --}}
                                @foreach ($req->getMedia('files') as $media)
                                    <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}" class="mt-2"/>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif
    </x-offer.object>
    </div>
</x-ui.cabinet>
