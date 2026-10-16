{{-- Карточка счёта или вознаграждения по сделке — тем же каркасом, что карточка ссылки (09.10.2026, владелец: «должно
     чувствоваться как приложение»): сверху кто кому («должен нам», «должны ему») и остаток крупно, «из N ₽» при частичной,
     светофор словом, плательщик; круглые кнопки «Деньги пришли» / «Выплатить» (шторка оплаты) и «PDF»; строки счёта —
     номер и дата, вид, ТС, менеджер (срок — словом под суммой). Ниже: заявки менеджера с «Поступило» / «Не поступила», ссылка на оплату
     строкой `x-billing.link-row` (нет — «Новая ссылка», `x-billing.pay-status`), состав с итогом и НДС, оплаты (у ссылки —
     способ, комиссия, «Вернуть»), переплата по ссылке; «Аннулировать» — красной строкой внизу. --}}
@php
    use App\Support\Money; use App\Billing\InvoiceState; use App\Billing\PaymentSource;
    $i = $invoice; $href = '/work/money/invoices/'.$i->id; $offer = $i->deal?->offer ?? $i->offer; $manager = $i->manager();
    $attempts = \App\Billing\Acquiring\AcquiringPayment::whereIn('link_id', $i->payLinks()->pluck('id'))->where('status', 'succeeded')->with('payout')->get();
    $overpaid = $attempts->filter(fn ($a) => $a->overpaid() > 0);
    $issued = $i->state === InvoiceState::Issued;
    $voidable = $issued && ! $i->payments()->whereNotIn('source', PaymentSource::soft())->exists();
    $link = $i->isOwed() ? null : $i->payLinks()->where('state', '!=', \App\Billing\Acquiring\PayLinkState::Canceled->value)->with(\App\Http\Admin\MoneyLinks::WITH)->latest('id')->first();
    $sum = $i->remaining() > 0 ? $i->remaining() : $i->total;
@endphp
<x-ui.detail>
    <x-ui.row-card :href="$href" :title="$i->isOwed() ? 'Выплата менеджеру '.$i->party->name : 'Счёт '.$i->label().', '.$i->party->name" :head="false" :photo="false" :action="false">
        <div class="money-hero">
            @if ($issued)<div class="money-hero-cap">{{ $i->isOwed() ? 'должны ему' : 'должен нам' }}</div>@endif
            <div class="money-hero-sum nums">{{ Money::nums($sum, fmod($sum, 1) ? 2 : 0) }}<small> ₽</small></div>
            @if ($i->isPartial())<div class="money-hero-car nums">из {{ Money::rub($i->total) }}</div>@endif
            <x-billing.light :invoice="$i" class="money-hero-state !text-sm"/>
            <div class="money-hero-who">{{ $i->party->name }}</div>
        </div>

        @if ($issued || $i->number)
            <div class="acts acts-hero">
                @if ($issued)
                    <div data-controller="sheet" class="contents">
                        <button type="button" class="act" data-action="sheet#open"><span class="btn btn-accent btn-round"><x-ui.icon name="{{ $i->isOwed() ? 'send' : 'check' }}"/></span>{{ $i->isOwed() ? 'Выплатить' : 'Деньги пришли' }}</button>
                        <x-ui.sheet :id="'paid-'.$i->id" :title="$i->isOwed() ? 'Выплата '.$i->party->name : 'Оплата по счёту '.$i->label()">
                            <x-billing.pay-form :invoice="$i" :action="$href.'/payments'" :sources="$sources" sheet/>
                        </x-ui.sheet>
                    </div>
                @endif
                @if ($i->number)<x-ui.doc :doc="['url' => $href.'/pdf', 'type' => 'pdf', 'name' => 'schet-'.$i->number.'.pdf', 'label' => 'Счёт '.$i->label()]" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="file"/></span>PDF</x-ui.doc>@endif
            </div>
        @endif

        <div class="list mt-6">
            <div class="row"><span class="shrink-0 text-ink-muted">{{ $i->isOwed() ? 'Выплата' : 'Счёт' }}</span><span class="min-w-0 flex-1 truncate text-right nums">{{ $i->isOwed() ? '' : $i->label().' ' }}от {{ $i->issued_at->translatedFormat('j M') }}</span></div>
            <div class="row"><span class="shrink-0 text-ink-muted">За что</span><span class="min-w-0 flex-1 truncate text-right">{{ $i->kind->label() }}</span></div>
            @if ($offer)
                <a href="{{ $i->deal_id ? '/offers/'.$offer->number : '/work/garage?preset=all&peek='.$offer->number }}" class="row" data-turbo-frame="_top">
                    <span class="shrink-0 text-ink-muted">ТС</span><span class="min-w-0 flex-1 truncate text-right">{{ $offer->titleWithYear() }}</span><x-ui.chevron/>
                </a>
            @endif
            @if ($manager)<div class="row"><span class="shrink-0 text-ink-muted">Менеджер</span><span class="min-w-0 flex-1 text-right"><x-ui.person :user="$manager"/></span></div>@endif
            @if ($i->isOwed() && ! $i->party->payoutReady())
                <div class="row"><span class="shrink-0 text-ink-muted">Реквизиты</span><span class="min-w-0 flex-1 text-right text-urgent">нет</span></div>
            @endif
        </div>

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
                                        <x-ui.sheet-foot variant="secondary">Отметить</x-ui.sheet-foot>
                                    </form>
                                </x-ui.sheet>
                            </span>
                        </x-slot:acts>
                    </x-money.payment>
                @endforeach
            </div>
        @endif

        {{-- Открытой ссылки нет, а платить ещё есть что (последнюю ссылку оплатили частично, отменили) — «Новая ссылка»
             строкой `x-billing.pay-status` под прежней. --}}
        @php $newLink = ! $i->isOwed() && ! $link?->isOpen() && \App\Billing\Acquiring\PayLink::eligible($i) && $i->remaining() - $i->claimed() > 0; @endphp
        @if ($link || $newLink)
            <div class="list-cap mt-5">Ссылка на оплату</div>
            <div class="list">
                @if ($link)<x-billing.link-row :link="$link" :car="false" :sub="\App\Http\Admin\MoneyLinks::who($link).', '.$link->created_at->translatedFormat('j M')"/>@endif
                @if ($newLink)<x-billing.pay-status :invoice="$i" staff/>@endif
            </div>
        @endif

        <div class="list-cap mt-5">Состав</div>
        <div class="list">
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

        @if ($voidable)
            <form method="post" action="{{ $href }}/void" class="list mt-5" data-turbo-confirm="Аннулировать {{ $i->isOwed() ? 'выплату' : 'счёт' }}?">
                @csrf<button class="row row-danger">Аннулировать</button>
            </form>
        @endif
    </x-ui.row-card>
    {{-- Действие в карточке (поступило, выплатили, аннулировали) меняет всю ленту «Оплат» — строка уходит из «Надо сделать» в «Историю»: страница перечитывается морфом с той же карточкой. --}}
    @if (session('toast'))<turbo-stream action="reload"></turbo-stream>@endif
</x-ui.detail>
