{{-- Карточка счёта или вознаграждения по сделке: в метках остаток, светофор словом, документ, менеджер, ТС; в ряду действий
     «Оплачен» / «Выплатить» — шторкой, PDF — шторкой документов, «Аннулировать» — в «···»; ссылка на оплату — первой
     строкой денег (`x-billing.pay-status`: адрес, «Отправить», что делал плательщик).
     Ниже строками денег: заявки менеджера с «Поступило» / «Не поступила», строки счёта с итогом и НДС, оплаты (у ссылки —
     способ, комиссия, зачислено ли на счёт, «Вернуть»), переплата по ссылке. --}}
@php
    use App\Support\Money; use App\Billing\InvoiceState; use App\Billing\PaymentSource;
    $i = $invoice; $href = '/work/money/invoices/'.$i->id; $offer = $i->deal?->offer ?? $i->offer; $manager = $i->manager();
    $attempts = \App\Billing\Acquiring\AcquiringPayment::whereIn('link_id', $i->payLinks()->pluck('id'))->where('status', 'succeeded')->with('payout')->get();
    $overpaid = $attempts->filter(fn ($a) => $a->overpaid() > 0);
    $issued = $i->state === InvoiceState::Issued;
    $voidable = $issued && ! $i->payments()->where('source', '!=', PaymentSource::Offset)->exists();
@endphp
<x-ui.detail>
    <x-ui.row-card :href="$href" :title="$i->isOwed() ? 'Выплата менеджеру '.$i->party->name : 'Счёт '.$i->label().', '.$i->party->name" :photo="$offer?->mainPhoto()">
        <x-slot:marks>
            {{-- Кто кому — словом у самой суммы (06.10.2026). --}}
            <span class="tag nums font-semibold">{{ $i->state === InvoiceState::Issued ? ($i->isOwed() ? 'должны ему ' : 'должен нам ') : '' }}{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}{{ $i->isPartial() ? ' из '.Money::rub($i->total) : '' }}</span>
            <x-billing.light :invoice="$i"/>
            <span class="tag nums">{{ $i->isOwed() ? 'выплата' : $i->label() }} от {{ $i->issued_at->translatedFormat('j M') }}</span>
            <span class="tag">{{ $i->kind->label() }}</span>
            @if ($manager)<x-ui.person :user="$manager"/>@endif
            @if ($offer)<a href="{{ $i->deal_id ? '/work/deals/'.$i->deal_id : '/work/garage?preset=all&peek='.$offer->number }}" class="tag">{{ $offer->titleWithYear() }}</a>@endif
            @if ($i->isOwed() && ! $i->party->payoutReady())<span class="tag text-urgent">реквизитов нет</span>@endif
        </x-slot:marks>
        <x-slot:actions>
            @if ($issued)
                <div data-controller="sheet" class="contents">
                    <button type="button" class="btn btn-s btn-accent" data-action="sheet#open">{{ $i->isOwed() ? 'Выплатить' : 'Деньги пришли' }}</button>
                    <x-ui.sheet :id="'paid-'.$i->id" :title="$i->isOwed() ? 'Выплата '.$i->party->name : 'Оплата по счёту '.$i->label()" :open="$errors->hasAny(['source', 'paid_at', 'slip'])">
                        <x-billing.pay-form :invoice="$i" :action="$href.'/payments'" :sources="$sources"/>
                    </x-ui.sheet>
                </div>
            @endif
            @if ($i->number)<x-ui.doc :doc="['url' => $href.'/pdf', 'type' => 'pdf', 'name' => 'schet-'.$i->number.'.pdf', 'label' => 'Счёт '.$i->label()]" class="btn btn-s btn-quiet"><x-ui.icon name="file" class="size-4"/>PDF</x-ui.doc>@endif
            @if ($voidable)
                <div class="contents" data-controller="menu">
                    <button type="button" class="btn btn-s btn-quiet btn-round" data-action="menu#toggle" aria-haspopup="menu" aria-controls="invoice-more-{{ $i->id }}" aria-label="Ещё"><x-ui.icon name="more" class="size-5"/></button>
                    <div id="invoice-more-{{ $i->id }}" class="menu" popover data-menu-target="list" role="menu">
                        <form method="post" action="{{ $href }}/void" data-turbo-confirm="Аннулировать {{ $i->isOwed() ? 'выплату' : 'счёт' }}?">@csrf<button class="menu-item w-full text-danger" role="menuitem" data-action="menu#close">Аннулировать</button></form>
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
            @unless ($i->isOwed())<x-billing.pay-status :invoice="$i" staff/>@endunless
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
    </x-ui.row-card>
    {{-- Действие в карточке (поступило, выплатили, аннулировали) меняет всю ленту «Оплат» — строка уходит из «Надо сделать» в «Историю»: страница перечитывается морфом с той же карточкой. --}}
    @if (session('toast'))<turbo-stream action="reload"></turbo-stream>@endif
</x-ui.detail>
