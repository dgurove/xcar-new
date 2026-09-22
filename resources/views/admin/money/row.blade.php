{{-- Счёт по сделке строкой: ТС и номер, менеджер, светофор, срок; заявленные оплаты — с кнопками прямо тут,
     вознаграждение к выплате — «Выплатить» раскрытием. Справа остаток. --}}
@php use App\Support\Money; use App\Billing\InvoiceState; $i = $invoice; $offer = $i->deal?->offer; @endphp
<div class="row items-start {{ $i->claims->isNotEmpty() ? 'bg-urgent-soft' : '' }}">
    @if ($offer)<a href="/work/money/invoices/{{ $i->id }}" class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="64px"/></a>@endif
    <div class="min-w-0 flex-1">
        <a href="/work/money/invoices/{{ $i->id }}" class="flex items-baseline gap-2">
            <span class="truncate font-medium">{{ $i->isOwed() ? 'Вознаграждение' : 'Счёт '.$i->label() }}</span>
        </a>
        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
            @if ($offer)<span class="tag nums">№ {{ $offer->number }}</span>@endif
            @if ($i->deal?->buyer)<x-ui.person :user="$i->deal->buyer"/>@endif
            @if ($i->party_id !== $i->deal?->buyer?->party_id)<span class="tag">{{ $i->party->name }}</span>@endif
            @if ($offer)<span class="tag">{{ $offer->titleWithYear() }}</span>@endif
            <x-billing.light :invoice="$i"/>
            
            @if ($i->isOwed() && ! $i->party->payoutReady())<x-ui.pill tone="urgent" class="!min-h-0 !py-1 text-xs">Реквизитов нет</x-ui.pill>@endif
        </div>
        @foreach ($i->claims as $p)
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="text-sm">Сообщил об оплате</span><span class="nums font-semibold">{{ Money::rub($p->amount) }}</span><span class="tag nums">{{ $p->paid_at->translatedFormat('j M') }}</span>
                @if ($p->slip())<a href="/work/money/invoices/{{ $i->id }}/payments/{{ $p->id }}/slip" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>платёжка</a>@endif
                <form method="post" action="/work/payments/{{ $p->id }}/confirm" class="contents" data-turbo-confirm="Поступило {{ Money::rub($p->amount) }}?">@csrf<x-ui.button size="sm">Поступило</x-ui.button></form>
                <details class="contents"><summary class="btn btn-s btn-ghost inline-flex cursor-pointer">Не поступила</summary>
                    <form method="post" action="/work/payments/{{ $p->id }}/reject" class="mt-2 flex w-full gap-2">@csrf<input name="reason" class="field-input min-w-0 flex-1" placeholder="Что не так"><x-ui.button size="sm" variant="secondary">Отметить</x-ui.button></form>
                </details>
            </div>
        @endforeach
        @if ($i->isAgentFee() && $i->state === InvoiceState::Issued)
            <div class="mt-2" data-controller="sheet">
                <x-ui.button type="button" size="sm" data-action="sheet#open">Выплатить</x-ui.button>
                <x-ui.sheet id="pay-{{ $i->id }}" :title="'Выплата '.$i->party->name"><x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" :sources="$sources"/></x-ui.sheet>
            </div>
        @endif
    </div>
    <div class="shrink-0 text-right">
        <div class="nums font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</div>
        @if ($i->isPartial())<div class="nums text-xs text-ink-muted">из {{ Money::rub($i->total) }}</div>@endif
    </div>
</div>
