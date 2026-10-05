{{-- Расчёт по сделке, как экран операции в банковском приложении: сверху фото ТС и число, которое сейчас важно, со
     словом состояния; путь денег точками (подтверждение → счёт → оплата → вознаграждение); дальше группы строк —
     счёт (строки, итог с НДС, оплаты, PDF) и вознаграждение с выплатами. Ссылка на оплату — сразу под числом
     (`x-billing.pay-status`: она заводится вместе со счётом, её только отправить). Плашка «Оплатить» — пока есть что
     платить. Закупочной и «нам» здесь нет. --}}
@php
    use App\Support\Money; use App\Offers\CommissionState; use App\Billing\InvoiceState; use App\Billing\DealMoney;
    $shows = $deal->showsCommission();
    $m = DealMoney::of($deal);
    // Под числом к оплате — срок: что происходит с оплатой, говорит путь ниже.
    $unpaid = $invoices->first(fn ($i) => $i->state === InvoiceState::Issued && $i->kind !== \App\Billing\ChargeKind::Reward);
    [$phrase, $tone] = $m->preset === 'pay' && $unpaid
        ? ($unpaid->isOverdue() ? ['просрочен на '.$unpaid->overdueDays().' дн', 'urgent'] : ['до '.$unpaid->due_at->translatedFormat('j F'), $unpaid->light() === 'urgent' ? 'urgent' : null])
        : [$m->phrase, $m->tone];
    // Подпись над числом уже говорит «Вам к выплате» — под числом только срок.
    if ($m->preset === 'payout') {
        $phrase = preg_replace('/^К выплате /u', '', $phrase);
    }
@endphp
<x-ui.cabinet :title="$offer->titleWithYear()" :back="['Деньги', '/account/money']">
    <div class="flex max-w-[30rem] flex-col gap-6">
        @if ($m->amount !== null)
            <x-money.hero :offer="$offer" :caption="$m->caption" :amount="$m->amount" :phrase="$phrase" :tone="$tone"/>
        @endif

        @php $byLink = $invoices->filter(fn ($i) => $i->openLink() || (\App\Billing\Acquiring\PayLink::eligible($i) && $i->remaining() - $i->claimed() > 0)); @endphp
        @if ($byLink->isNotEmpty())
            <div class="list">
                {{-- Строкой: сумма уже крупно сверху и в «Остатке» счёта — третий раз её не пишем. --}}
                @foreach ($byLink as $i)<x-billing.pay-status :invoice="$i" compact/>@endforeach
            </div>
        @endif

        <div class="box"><x-money.track :steps="DealMoney::track($deal)"/></div>

        @foreach ($invoices as $i)
            <section>
                <div class="list-cap"><span class="min-w-0 flex-1">Счёт {{ $i->label() }} от {{ $i->issued_at->translatedFormat('j M') }}@if ($i->party_id !== auth()->user()->party_id), платит {{ $i->party->name }}@endif</span>@if ($i->isNot($unpaid))<x-billing.light :invoice="$i"/>@endif</div>
                <div class="list">
                    @foreach ($i->charges as $c)
                        <div class="row"><span class="min-w-0 flex-1 text-ink-muted">{{ $c->title }}</span><span class="nums shrink-0">{{ Money::rub($c->amount) }}</span></div>
                    @endforeach
                    <div class="row">
                        <span class="min-w-0 flex-1">Итого@if ($i->vatRate())<span class="row-sub">{{ mb_strtolower($i->vatLabel()) }} {{ Money::rub($i->vatAmount()) }}</span>@endif</span>
                        <span class="nums shrink-0 font-semibold">{{ Money::rub($i->total) }}</span>
                    </div>
                    @foreach ($i->allPayments as $p)
                        <x-money.payment :payment="$p" :slip="'/account/money/invoices/'.$i->id.'/payments/'.$p->id.'/slip'"/>
                    @endforeach
                    @if ($i->state === InvoiceState::Issued && $i->paid > 0 && $i->remaining() > 0)
                        <div class="row"><span class="min-w-0 flex-1 font-medium">Остаток</span><span class="nums shrink-0 font-semibold">{{ Money::rub($i->remaining()) }}</span></div>
                    @endif
                    @if ($i->getFirstMedia('file'))
                        <x-ui.doc :doc="['url' => '/account/invoices/'.$i->id.'/pdf', 'type' => 'pdf', 'name' => 'schet-'.$i->number.'.pdf', 'label' => 'Счёт '.$i->label()]" class="row">
                            <x-ui.row-icon name="file" size="s"/><span class="min-w-0 flex-1">Счёт PDF</span><x-ui.chevron/>
                        </x-ui.doc>
                    @endif
                </div>
            </section>
        @endforeach

        {{-- Крупное число сверху уже про вознаграждение — секция нужна, только чтобы перечислить выплаты. --}}
        @if ($shows && (! in_array($m->caption, ['Вам к выплате', 'Ваше вознаграждение'], true) || $fee?->payments->isNotEmpty()))
            <section>
                {{-- «Ваше» — чтобы не путать со строкой счёта ПРАЙМ «Агентское вознаграждение» на всю разницу. --}}
                <div class="list-cap"><span class="min-w-0 flex-1">Ваше вознаграждение</span></div>
                <div class="list">
                    <div class="row">
                        <span class="min-w-0 flex-1 {{ $state === CommissionState::Payable ? 'text-accent-text' : '' }}">{{ $state->label() }}{{ $state === CommissionState::Payable && $fee ? ' до '.$fee->due_at->translatedFormat('j M') : '' }}</span>
                        <span class="nums shrink-0 font-semibold {{ $state === CommissionState::Payable ? 'text-accent-text' : '' }}">{{ Money::rub($deal->commission) }}</span>
                    </div>
                    @foreach ($fee?->payments ?? [] as $p)
                        <x-money.payment :payment="$p" title="Выплачено" icon="wallet" :slip="'/account/money/invoices/'.$fee->id.'/payments/'.$p->id.'/slip'"/>
                    @endforeach
                    @if ($fee && $fee->state === InvoiceState::Issued && $fee->paid > 0)
                        <div class="row"><span class="min-w-0 flex-1">Осталось выплатить</span><span class="nums shrink-0 font-semibold">{{ Money::rub($fee->remaining()) }}</span></div>
                    @endif
                </div>
            </section>
        @endif

        <div class="list">
            <a href="/deals/{{ $deal->id }}" class="row"><span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span><span class="min-w-0 flex-1">Сделка<span class="row-sub nums">№ {{ $offer->number }}</span></span><x-ui.chevron/></a>
        </div>
    </div>

    @if ($claimable->isNotEmpty())
        <div data-controller="sheet">
            <x-ui.action-bar><x-ui.button type="button" class="min-w-0 flex-1" data-action="sheet#open">Оплатить</x-ui.button></x-ui.action-bar>
            <x-billing.pay-sheet :invoices="$claimable" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf" :buyers="$buyers" :open="$errors->any() || request()->boolean('pay')"/>
        </div>
    @endif
</x-ui.cabinet>
