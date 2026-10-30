{{-- Счёт сделки шторкой (19.10.2026, владелец: «менеджер путает страницу оплаты счёта и страницу сделки — объединить»):
     то, что жило на отдельном расчёте «Оплат», — строки счёта, итог с НДС, оплаты с платёжками, остаток, PDF и ссылка на
     оплату. Открывается строкой счёта в «Расчёте» и в задаче; `?invoice={id}` — сразу открыта. --}}
@php
    use App\Support\Money;
    use App\Billing\InvoiceState;
    $i = $invoice;
@endphp
<x-ui.sheet :id="'invoice-'.$i->id" :title="'Счёт '.$i->label().' от '.\App\Support\Ago::day($i->issued_at)" :open="(int) request('invoice') === $i->id">
    <div class="list">
        @if ($i->party_id !== auth()->user()->party_id)
            <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Платит</span><span class="min-w-0 truncate">{{ $i->party?->name }}</span></div>
        @endif
        @foreach ($i->charges as $c)
            <div class="row"><span class="min-w-0 flex-1 text-ink-muted">{{ $c->title }}</span><span class="nums shrink-0">{{ Money::rub($c->amount) }}</span></div>
        @endforeach
        <div class="row">
            <span class="min-w-0 flex-1">Итого@if ($i->vatRate())<span class="row-sub">{{ mb_lcfirst($i->vatLabel()) }} {{ Money::rub($i->vatAmount()) }}</span>@endif</span>
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
    @if ($i->state === InvoiceState::Issued && ($i->openLink() || \App\Billing\Acquiring\PayLink::eligible($i)))
        <div class="list mt-4"><x-billing.pay-status :invoice="$i" compact/></div>
    @endif
</x-ui.sheet>
