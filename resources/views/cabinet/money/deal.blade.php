{{-- Расчёт по сделке — как документ сверху вниз: цена → счета со строками, оплатами и заявками → агентское
     вознаграждение → выплаты; ниже история этой сделки. Плашка «Сообщить об оплате» — пока есть что платить.
     Закупочной и «нам» здесь нет. --}}
@php
    use App\Support\Money; use App\Offers\CommissionState; use App\Billing\InvoiceState; use App\Billing\PaymentState; use App\Billing\PaymentSource;
    $shows = $deal->showsCommission();
@endphp
<x-ui.cabinet :title="$offer->titleWithYear()" :back="['Деньги', '/account/money']">
    <div class="flex max-w-[30rem] flex-col gap-6">
        <div class="box">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-xl">Расчёт</h2>
                <span class="tag nums">№ {{ $offer->number }}</span>
            </div>
            <dl class="mt-4 flex flex-col">
                <div class="flex items-baseline justify-between gap-3 py-2"><dt>Цена подтверждения</dt><dd class="nums font-semibold">{{ Money::rub($deal->amount) }}</dd></div>

                @foreach ($invoices as $i)
                    <div class="mt-3 border-t border-line/40 pt-3">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="font-medium">Счёт {{ $i->label() }}</span>
                            <span class="tag nums">от {{ $i->issued_at->translatedFormat('j M') }}</span>
                            <x-billing.light :invoice="$i"/>
                            @if ($i->getFirstMedia('file'))<a href="/account/invoices/{{ $i->id }}/pdf" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>PDF</a>@endif
                            @if ($i->party_id !== auth()->user()->party_id)<span class="tag">платит {{ $i->party->name }}</span>@endif
                        </div>
                        <div class="mt-2 flex flex-col text-sm">
                            @foreach ($i->charges as $c)
                                <div class="flex items-baseline justify-between gap-3 py-1 pl-4"><span class="min-w-0 text-ink-muted">{{ $c->title }}</span><span class="nums shrink-0">{{ Money::rub($c->amount) }}</span></div>
                            @endforeach
                            @foreach ($i->allPayments as $p)
                                @php [$text, $cls] = match (true) {
                                    $p->state === PaymentState::Claimed => ['Сообщили об оплате '.$p->paid_at->translatedFormat('j M').', ждёт подтверждения', 'text-urgent'],
                                    $p->state === PaymentState::Rejected => ['Не поступила'.($p->reject_reason ? ': '.$p->reject_reason : ''), 'text-ink-muted line-through'],
                                    $p->source === PaymentSource::Offset => ['Удержано агентское вознаграждение', 'text-ink-muted'],
                                    default => ['Оплачено '.$p->paid_at->translatedFormat('j M'), ''],
                                }; @endphp
                                <div class="flex items-baseline justify-between gap-3 py-1 {{ $cls }}"><span class="min-w-0">{{ $text }}@if ($p->slip()) <a href="/account/money/invoices/{{ $i->id }}/payments/{{ $p->id }}/slip" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>платёжка</a>@endif</span><span class="nums shrink-0">{{ Money::rub($p->amount) }}</span></div>
                            @endforeach
                            @if ($i->state === InvoiceState::Issued && $i->remaining() > 0)
                                <div class="flex items-baseline justify-between gap-3 py-1 {{ $i->isOverdue() ? 'text-urgent' : '' }}"><span class="font-medium">Остаток{{ $i->isOverdue() ? ', просрочен на '.$i->overdueDays().' дн' : ', до '.$i->due_at->translatedFormat('j M') }}</span><span class="nums shrink-0 font-semibold">{{ Money::rub($i->remaining()) }}</span></div>
                            @endif
                        </div>
                    </div>
                @endforeach

                @if ($shows)
                    <div class="mt-3 border-t border-line/40 pt-3">
                        <div class="flex items-baseline justify-between gap-3"><dt class="font-medium">Агентское вознаграждение</dt><dd class="nums font-semibold {{ $state === CommissionState::Payable ? 'text-accent-text' : '' }}">{{ Money::rub($deal->commission) }}</dd></div>
                        <div class="mt-1.5"><x-ui.pill :tone="$state->tone()" class="!min-h-0 !py-1 text-xs">{{ mb_strtolower($state->label()) }}{{ $state === CommissionState::Payable && $fee ? ' до '.$fee->due_at->translatedFormat('j M') : '' }}</x-ui.pill></div>
                    </div>
                    @if ($fee)
                        <div class="flex flex-col text-sm">
                            @foreach ($fee->payments as $p)
                                <div class="flex items-baseline justify-between gap-3 py-1 text-accent-text"><span class="min-w-0">Выплачено {{ $p->paid_at->translatedFormat('j M') }}@if ($p->slip()) <a href="/account/money/invoices/{{ $fee->id }}/payments/{{ $p->id }}/slip" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>платёжка</a>@endif</span><span class="nums shrink-0">{{ Money::rub($p->amount) }}</span></div>
                            @endforeach
                            @if ($fee->state === InvoiceState::Issued && $fee->paid > 0)<div class="flex items-baseline justify-between gap-3 py-1"><span>Осталось выплатить</span><span class="nums shrink-0 font-semibold">{{ Money::rub($fee->remaining()) }}</span></div>@endif
                        </div>
                    @endif
                @endif
            </dl>
        </div>

        @if ($history->isNotEmpty())
            <div class="box">
                <h2 class="text-xl">История</h2>
                <div class="mt-3 flex flex-col divide-y divide-line/40 text-sm">
                    @foreach ($history as $r)
                        <div class="flex items-baseline gap-3 py-2"><span class="nums shrink-0 text-ink-dim">{{ $r['at']->translatedFormat('j M') }}</span><span class="min-w-0 flex-1">{{ $r['title'] }}</span><span class="nums shrink-0 text-ink-muted">{{ Money::rub($r['amount']) }}</span></div>
                    @endforeach
                </div>
            </div>
        @endif

        <a href="/account/deals/{{ $deal->id }}" class="row"><span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span><span class="min-w-0 flex-1 font-medium">Сделка</span><x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/></a>
    </div>

    @if ($claimable->isNotEmpty())
        <div data-controller="sheet">
            <x-ui.action-bar><x-ui.button type="button" class="min-w-0 flex-1" data-action="sheet#open">Сообщить об оплате</x-ui.button></x-ui.action-bar>
            <x-ui.sheet id="claim" title="Сообщить об оплате" :open="$errors->any()">
                @php $first = $claimable->first(); @endphp
                <form method="post" action="/account/money/invoices/{{ $first->id }}/claims" enctype="multipart/form-data" class="flex flex-col gap-3" data-controller="claim-target">
                    @csrf
                    @if ($claimable->count() > 1)
                        <x-ui.field name="invoice" label="Счёт" :options="$claimable->mapWithKeys(fn ($i) => [$i->id => $i->label().', остаток '.Money::rub($i->remaining() - $i->claimed())])->all()" data-action="claim-target#pick"/>
                    @endif
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="amount" label="Сумма, ₽" :value="rtrim(rtrim(number_format($first->remaining() - $first->claimed(), 2, '.', ''), '0'), '.')" inputmode="decimal" required/>
                        <x-ui.field name="paid_at" label="Дата оплаты" type="date" :value="now()->toDateString()" required/>
                        <x-ui.field name="ref" label="№ платёжки"/>
                        <x-ui.field name="slip" label="Платёжное поручение" type="file" accept=".pdf,.jpg,.jpeg,.png,.heic" required/>
                    </div>
                    <x-ui.button block>Отправить</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
    @endif
</x-ui.cabinet>
