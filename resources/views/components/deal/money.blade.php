{{-- Деньги сделки для сотрудника: расклад (цена, закупочная снимком, разница, вознаграждение, нам), чипы —
     режим, состояние вознаграждения, «Изменить» до счёта, «Счёт»; ниже счета строками, заявка менеджера
     об оплате с решением под своим счётом, у вознаграждения к выплате — «Выплатить» в самой строке.
     Менеджеру ничего из этого не показывается. --}}
@props(['deal'])
@php
    use App\Support\Money; use App\Billing\InvoiceState; use App\Offers\CommissionState;
    $offer = $deal->offer;
    $invoices = $deal->invoices()->with(['party', 'claims.media'])->get();
    $fee = $invoices->first(fn ($i) => $i->isAgentFee());
    $issued = $invoices->reject(fn ($i) => $i->isOwed());
    $state = $deal->commissionState();
    $party = $deal->buyer ? \App\Billing\Party::forUser($deal->buyer, false) : null;
@endphp
<x-ui.card title="Деньги" {{ $attributes }}>
    <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
        <dt class="text-sm text-ink-dim">Цена подтверждения</dt><dd class="nums text-right font-medium">{{ Money::rub($deal->amount) }}</dd>
        <dt class="text-sm text-ink-dim">Закупочная</dt><dd class="nums text-right">{{ $deal->cost === null ? 'не указана' : Money::rub($deal->cost) }}</dd>
        @if ($deal->margin() !== null)<dt class="text-sm text-ink-dim">Разница</dt><dd class="nums text-right {{ $deal->margin() < 0 ? 'text-danger' : '' }}">{{ Money::rub($deal->margin()) }}</dd>@endif
        <dt class="text-sm text-ink-dim">Вознаграждение</dt><dd class="nums text-right">{{ $deal->commission ? Money::rub($deal->commission) : 'нет' }}</dd>
        @if ($deal->ours() !== null)<dt class="text-sm text-ink-dim">Нам</dt><dd class="nums text-right text-lg font-semibold {{ $deal->ours() < 0 ? 'text-danger' : '' }}">{{ Money::rub($deal->ours()) }}</dd>@endif
    </dl>
    <div class="mt-3 flex flex-wrap items-center gap-1.5">
        @if ($deal->commission)<span class="tag">{{ mb_strtolower($deal->commission_mode->label()) }}</span>@endif
        @if ($state !== CommissionState::Hidden)<x-ui.pill :tone="$state->tone()" class="!min-h-0 !py-1 text-xs">{{ mb_strtolower($state->label()) }}{{ $state === CommissionState::Payable && $fee ? ' до '.$fee->due_at->translatedFormat('j M') : '' }}{{ $state === CommissionState::Paid && $fee?->paid_at ? ' '.$fee->paid_at->translatedFormat('j M') : '' }}</x-ui.pill>@endif
        @if ($deal->commission && $party && ! $party->payoutReady())<x-ui.pill tone="urgent" class="!min-h-0 !py-1 text-xs">Реквизитов для выплаты нет</x-ui.pill>@endif
        @if ($deal->isActive())
            @if ($deal->commissionEditable())
                <div data-controller="sheet" class="contents">
                    <button type="button" class="chip" data-action="sheet#open">Изменить</button>
                    <x-ui.sheet id="deal-money-{{ $deal->id }}" title="Агентское вознаграждение" :open="$errors->has('commission')">
                        <x-offer.money-form :action="'/work/deals/'.$deal->id.'/money'" method="put" :amount="$deal->amount" :cost="$deal->cost" :commission="$deal->commission" :mode="$deal->commission_mode" submit="Сохранить"/>
                    </x-ui.sheet>
                </div>
            @endif
            <a href="/work/invoices/new?offer={{ $offer->number }}" class="chip">{{ $issued->isEmpty() ? 'Выставить счёт' : 'Ещё счёт' }}</a>
        @endif
    </div>
    @if ($invoices->isNotEmpty())
        <div class="mt-3 flex flex-col gap-2">
            @foreach ($invoices as $i)
                <div class="row !py-2.5 {{ $i->claims->isNotEmpty() ? 'bg-urgent-soft' : '' }}">
                    <a href="/work/money/invoices/{{ $i->id }}" class="min-w-0 flex-1">
                        <span class="block truncate">{{ $i->isOwed() ? 'Вознаграждение менеджеру' : 'Счёт '.$i->label() }}@unless ($i->isOwed())<span class="text-ink-muted"> {{ $i->party->name }}</span>@endunless</span>
                        <span class="row-sub"><x-billing.light :invoice="$i"/>@if ($i->isPartial())<span class="tag nums">из {{ Money::rub($i->total) }}</span>@endif</span>
                    </a>
                    <span class="flex shrink-0 flex-col items-end gap-1.5">
                        <span class="nums font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
                        @if ($i->isAgentFee() && $i->state === InvoiceState::Issued)
                            <span data-controller="sheet" class="contents">
                                <x-ui.button type="button" size="sm" data-action="sheet#open">Выплатить</x-ui.button>
                                <x-ui.sheet id="pay-{{ $i->id }}" :title="'Выплата '.$i->party->name"><x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'"/></x-ui.sheet>
                            </span>
                        @endif
                    </span>
                </div>
                @foreach ($i->claims as $p)
                    <div class="flex flex-wrap items-center gap-2 pl-3">
                        <span class="text-sm">Сообщил об оплате</span><span class="nums font-semibold">{{ Money::rub($p->amount) }}</span><span class="tag nums">{{ $p->paid_at->translatedFormat('j M') }}</span>
                        @if ($p->slip())<a href="/work/money/invoices/{{ $i->id }}/payments/{{ $p->id }}/slip" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>платёжка</a>@endif
                        <form method="post" action="/work/payments/{{ $p->id }}/confirm" class="contents" data-turbo-confirm="Поступило {{ Money::rub($p->amount) }}?">@csrf<x-ui.button size="sm">Поступило</x-ui.button></form>
                        <a href="/work/money/invoices/{{ $i->id }}" class="btn btn-s btn-ghost">Не поступила</a>
                    </div>
                @endforeach
            @endforeach
        </div>
    @endif
</x-ui.card>
