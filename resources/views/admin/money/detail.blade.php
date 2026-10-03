{{-- Карточка счёта или вознаграждения по сделке: в метках остаток, светофор словом, документ, менеджер, ТС; в ряду действий
     «Оплачен» / «Выплатить» и «Ссылка на оплату» — шторками, PDF — шторкой документов, «Аннулировать» — в «···».
     Ниже строками денег: заявки менеджера с «Поступило» / «Не поступила», строки счёта с итогом и НДС, оплаты (у ссылки —
     способ, комиссия, зачислено ли на счёт, «Вернуть»), переплата по ссылке. --}}
@php
    use App\Support\Money; use App\Billing\InvoiceState; use App\Billing\PaymentSource;
    $i = $invoice; $href = '/work/money/invoices/'.$i->id; $offer = $i->deal?->offer;
    $link = $i->openLink();
    $attempts = \App\Billing\Acquiring\AcquiringPayment::whereIn('link_id', $i->payLinks()->pluck('id'))->where('status', 'succeeded')->with('payout')->get();
    $overpaid = $attempts->filter(fn ($a) => $a->overpaid() > 0);
    $issued = $i->state === InvoiceState::Issued;
    $online = $issued && ! $i->isOwed() && app(\App\Billing\Acquiring\Gateway::class)->configured();
    $voidable = $issued && ! $i->payments()->where('source', '!=', PaymentSource::Offset)->exists();
    $suggest = \App\Billing\Acquiring\PayLink::defaultAmount($i);
@endphp
<x-ui.detail>
    <x-ui.row-card :href="$href" :title="$i->isOwed() ? 'Вознаграждение '.$i->party->name : 'Счёт '.$i->label().', '.$i->party->name" :photo="$offer?->mainPhoto()">
        <x-slot:marks>
            <span class="tag nums font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}{{ $i->isPartial() ? ' из '.Money::rub($i->total) : '' }}</span>
            <x-billing.light :invoice="$i"/>
            <span class="tag nums">{{ $i->isOwed() ? 'обязательство' : $i->label() }} от {{ $i->issued_at->translatedFormat('j M') }}</span>
            <span class="tag">{{ $i->kind->label() }}</span>
            @if ($i->deal?->buyer)<x-ui.person :user="$i->deal->buyer"/>@endif
            @if ($offer)<a href="/work/deals/{{ $i->deal_id }}" class="tag">{{ $offer->titleWithYear() }}</a>@endif
            @if ($i->isOwed() && ! $i->party->payoutReady())<span class="tag text-urgent">реквизитов нет</span>@endif
        </x-slot:marks>
        <x-slot:actions>
            @if ($issued)
                <div data-controller="sheet" class="contents">
                    <button type="button" class="btn btn-s btn-accent" data-action="sheet#open">{{ $i->isOwed() ? 'Выплатить' : 'Оплачен' }}</button>
                    <x-ui.sheet :id="'paid-'.$i->id" :title="$i->isOwed() ? 'Выплата '.$i->party->name : 'Оплата по счёту '.$i->label()" :open="$errors->hasAny(['source', 'paid_at', 'slip'])">
                        <x-billing.pay-form :invoice="$i" :action="$href.'/payments'" :sources="$sources"/>
                    </x-ui.sheet>
                </div>
            @endif
            @if ($link)
                <div data-controller="sheet" class="contents">
                    <button type="button" class="btn btn-s btn-quiet" data-action="sheet#open"><x-ui.icon name="qr" class="size-4"/>Ссылка {{ Money::rub($link->amount) }}</button>
                    <x-ui.sheet :id="'link-'.$link->id" title="Ссылка на оплату" :open="session('open-link') === $link->id">
                        <div class="flex flex-col gap-4">
                            <div class="money-hero">
                                <span class="nums text-[32px] font-semibold leading-tight">{{ Money::rub($link->amount) }}</span>
                                <span class="text-ink-muted">платит {{ $link->payerLabel() }}@if ($link->payer_email), чек на {{ $link->payer_email }}@endif</span>
                            </div>
                            <div class="mx-auto w-52 rounded-(--radius-l) bg-white p-3 text-black">{!! \App\Support\Qr::svg($link->url()) !!}</div>
                            <x-ui.copy-link :url="$link->url()" :title="'Оплата по счёту '.$i->label()"/>
                            <form method="post" action="/work/money/links/{{ $link->id }}" data-turbo-confirm="Отменить ссылку? Оплатить по ней будет нельзя">
                                @csrf @method('delete')
                                <x-ui.button variant="ghost" block class="text-ink-muted">Отменить ссылку</x-ui.button>
                            </form>
                        </div>
                    </x-ui.sheet>
                </div>
            @elseif ($online)
                <div data-controller="sheet" class="contents">
                    <button type="button" class="btn btn-s btn-quiet" data-action="sheet#open"><x-ui.icon name="qr" class="size-4"/>Ссылка на оплату</button>
                    <x-ui.sheet :id="'new-link-'.$i->id" title="Ссылка на оплату" :open="$errors->hasAny(['email', 'phone', 'amount'])">
                        <form method="post" action="{{ $href }}/links" class="flex flex-col gap-4">
                            @csrf
                            <label class="flex items-baseline justify-center gap-2">
                                <input name="amount" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Сумма, ₽" placeholder="0"
                                    value="{{ old('amount', Money::nums($suggest, fmod($suggest, 1) ? 2 : 0)) }}" data-controller="digits" data-action="input->digits#format">
                                <span class="text-2xl text-ink-muted">₽</span>
                            </label>
                            @error('amount')<div class="-mt-2 text-center text-sm text-danger">{{ $message }}</div>@enderror
                            <x-ui.field name="name" id="link-name" label="Плательщик" :value="$i->party->name"/>
                            <div class="grid grid-cols-2 gap-3">
                                <x-ui.field name="email" id="link-email" label="Почта для чека" type="email" :value="$i->party->email"/>
                                <x-ui.field name="phone" id="link-phone" label="Телефон" :value="$i->party->phone"/>
                            </div>
                            <x-ui.button block>Получить ссылку</x-ui.button>
                        </form>
                    </x-ui.sheet>
                </div>
            @endif
            @if ($i->number)<x-ui.doc :doc="['url' => $href.'/pdf', 'type' => 'pdf', 'name' => 'schet-'.$i->number.'.pdf', 'label' => 'Счёт '.$i->label()]" class="btn btn-s btn-quiet"><x-ui.icon name="file" class="size-4"/>PDF</x-ui.doc>@endif
            @if ($voidable)
                <div class="contents" data-controller="menu">
                    <button type="button" class="btn btn-s btn-quiet btn-round" data-action="menu#toggle" aria-haspopup="menu" aria-controls="invoice-more-{{ $i->id }}" aria-label="Ещё"><x-ui.icon name="more" class="size-5"/></button>
                    <div id="invoice-more-{{ $i->id }}" class="menu" popover data-menu-target="list" role="menu">
                        <form method="post" action="{{ $href }}/void" data-turbo-confirm="Аннулировать {{ $i->isOwed() ? 'обязательство' : 'счёт' }}?">@csrf<button class="menu-item w-full text-danger" role="menuitem" data-action="menu#close">Аннулировать</button></form>
                    </div>
                </div>
            @endif
        </x-slot:actions>

        @if ($i->claims->isNotEmpty())
            <div class="list mt-4">
                @foreach ($i->claims as $p)
                    <x-money.payment :payment="$p" :slip="$href.'/payments/'.$p->id.'/slip'" staff>
                        <x-slot:acts>
                            <form method="post" action="/work/payments/{{ $p->id }}/confirm" class="contents" data-turbo-confirm="Поступило {{ Money::rub($p->amount) }}?">@csrf<button class="btn btn-s btn-accent">Поступило</button></form>
                            <span data-controller="sheet" class="contents">
                                <button type="button" class="btn btn-s btn-quiet" data-action="sheet#open">Не поступила</button>
                                <x-ui.sheet :id="'reject-'.$p->id" title="Не поступила">
                                    <form method="post" action="/work/payments/{{ $p->id }}/reject" class="flex flex-col gap-4">
                                        @csrf
                                        <x-ui.field name="reason" :id="'reason-'.$p->id" label="Что не так"/>
                                        <x-ui.button block variant="secondary">Отметить</x-ui.button>
                                    </form>
                                </x-ui.sheet>
                            </span>
                        </x-slot:acts>
                    </x-money.payment>
                @endforeach
            </div>
        @endif

        <div class="list mt-4">
            @foreach ($i->charges as $c)
                <div class="row"><span class="min-w-0 flex-1 text-ink-muted">{{ $c->title }}</span><span class="nums shrink-0">{{ Money::rub($c->amount) }}</span></div>
            @endforeach
            <div class="row">
                <span class="min-w-0 flex-1">Итого@if ($i->vatRate())<span class="row-sub">{{ mb_strtolower($i->vatLabel()) }} {{ Money::rub($i->vatAmount()) }}</span>@endif</span>
                <span class="nums shrink-0 font-semibold">{{ Money::rub($i->total) }}</span>
            </div>
            @foreach ($i->payments as $p)
                @php $a = $p->source === PaymentSource::Acquiring ? $attempts->firstWhere('payment_id', $p->id) : null; @endphp
                <x-money.payment :payment="$p" :attempt="$a" :slip="$href.'/payments/'.$p->id.'/slip'" staff>
                    @if ($a && $a->refundable() > 0)
                        <x-slot:acts>
                            <form method="post" action="/work/money/acquiring/{{ $a->id }}/refund" class="contents" data-turbo-confirm="Вернуть {{ Money::exact($a->refundable()) }} плательщику? Оплата по счёту отменится">@csrf<button class="chip text-ink-muted">Вернуть</button></form>
                        </x-slot:acts>
                    @endif
                </x-money.payment>
            @endforeach
            @foreach ($overpaid as $a)
                <x-money.line icon="offset" title="Переплата по ссылке" :amount="$a->overpaid()" tone="urgent">
                    <x-slot:acts>
                        <form method="post" action="/work/money/acquiring/{{ $a->id }}/refund" class="contents" data-turbo-confirm="Вернуть {{ Money::exact($a->overpaid()) }} плательщику?">@csrf<input type="hidden" name="surplus" value="1"><button class="btn btn-s btn-quiet">Вернуть</button></form>
                    </x-slot:acts>
                </x-money.line>
            @endforeach
        </div>
        <x-slot:row><x-money.table-row :invoice="$i"/></x-slot:row>
    </x-ui.row-card>
</x-ui.detail>
