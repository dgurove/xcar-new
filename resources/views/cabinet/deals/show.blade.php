@php
    use App\Offers\CommissionState;
    use App\Offers\DealState;
    $feeState = $deal->commissionState();
    // Ждущие оплаты счета — в карточке шага (`cabinet.deals.step`), оплаченные и аннулированные — отдельной карточкой ниже пути.
    $pastInvoices = $invoices->reject(fn ($i) => $i->state === \App\Billing\InvoiceState::Issued);
@endphp
<x-ui.cabinet :title="$offer->titleWithYear()" :back="['Сделки', '/deals']">
    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]" data-deal-offer="{{ $offer->number }}">
        <div class="min-w-0 space-y-6">
            {{-- Телефон: что за ТС и за сколько — первой строкой, как шапка разговора; справа на ПК это карточка. --}}
            <a href="/offers/{{ $offer->number }}" class="list lg:hidden">
                <span class="row">
                    <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
                    <span class="min-w-0 flex-1">
                        <span class="nums block text-lg font-semibold">{{ \App\Support\Money::rub($deal->amount) }}</span>
                        <span class="row-sub nums">№ {{ $offer->number }}@if ($deal->state !== DealState::Active), <span class="{{ $deal->state === DealState::Done ? 'text-open' : 'text-danger' }}">{{ mb_strtolower($deal->state->label()) }}</span>@endif</span>
                    </span>
                    <x-ui.chevron/>
                </span>
            </a>
            @include('cabinet.deals.step')

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
        </div>

        {{-- Правая колонка: машина, номер, сумма. Зеркало страницы оффера. --}}
        <aside class="lg:self-start">
            <div class="box overflow-hidden !p-0">
                <a href="/offers/{{ $offer->number }}" class="group block max-lg:hidden">
                    @if ($photo = $offer->mainPhoto())
                        <x-offer.photo :media="$photo" sizes="(min-width: 1024px) 320px, 100vw" class="aspect-[4/3] w-full object-cover transition-transform duration-500 group-hover:scale-105"/>
                    @endif
                    <div class="p-6">
                        <p class="nums text-[32px] font-bold leading-none">{{ \App\Support\Money::rub($deal->amount) }}</p>
                    </div>
                </a>
                <div class="px-6 pb-6 max-lg:pt-5">
                    {{-- На телефоне фото и цена — строкой сверху страницы, здесь остаются номера и адрес. --}}
                    <h2 class="box-title mb-3 lg:hidden">Транспортное средство</h2>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-3">
                        {{-- Своя сделка — всё, что нужно забрать машину: полный VIN, ДЛ, адрес; номера копируются. --}}
                        @foreach (['Предложение' => $offer->number, 'ДЛ' => $offer->leaseRef(), 'Год' => $offer->year, 'VIN' => $offer->vin, 'Город' => $offer->settlement?->title(), 'Адрес' => $offer->inspection_address] as $label => $value)
                            @if ($value)<div @class(['min-w-0', 'col-span-2' => in_array($label, ['VIN', 'Адрес'], true)])><dt class="text-sm text-ink-dim">{{ $label }}</dt><dd class="nums mt-0.5 break-words font-normal">@if ($label === 'VIN')<x-ui.vin-code :vin="$value"/>@elseif (in_array($label, ['Город', 'Адрес'], true))<x-ui.place>{{ $value }}</x-ui.place>@elseif (in_array($label, ['ДЛ', 'Предложение'], true))<x-ui.copy-code :value="(string) $value"/>@else{{ $value }}@endif</dd></div>@endif
                        @endforeach
                        {{-- Вознаграждение открывается со счёта (до него менеджер видит только цену) — фактом карточки, ссылкой на
                             расчёт; раньше это была серая плашка внутри карточки. --}}
                        @if ($feeState !== CommissionState::Hidden)
                            <div class="col-span-2 min-w-0"><dt class="text-sm text-ink-dim">Агентское вознаграждение</dt><dd class="mt-0.5 flex flex-wrap items-baseline justify-between gap-2"><a href="/account/money/deals/{{ $deal->id }}" class="nums font-semibold">{{ \App\Support\Money::rub($deal->commission) }}</a><x-ui.state :tone="$feeState->tone()">{{ mb_strtolower($feeState->label()) }}</x-ui.state></dd></div>
                        @endif
                    </dl>
                </div>
            </div>
            @if ($offer->chatOpenFor(auth()->user()))
                <a href="/account/chats/offer/{{ $offer->number }}" class="btn btn-quiet mt-3 w-full"><x-ui.icon name="chat" class="size-5"/> Написать по сделке</a>
            @endif
        </aside>
    </div>
</x-ui.cabinet>
