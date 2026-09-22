{{-- Расклад по сделке для менеджера: цена подтверждения, счета с оплатами, агентское вознаграждение с состоянием
     и выплатами. Закупочной и «нам» тут нет и не будет. --}}
@php use App\Support\Money; use App\Offers\CommissionState; use App\Billing\InvoiceState; @endphp
<x-ui.cabinet :title="$offer->titleWithYear()" :back="['Деньги', '/account/money']">
    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
        <div class="min-w-0 space-y-6">
            <div class="box">
                <h2 class="text-xl">Агентское вознаграждение</h2>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="nums text-[28px] font-bold leading-none">{{ Money::rub($deal->commission) }}</span>
                    <x-ui.pill :tone="$state->tone()">{{ $state->label() }}{{ $state === CommissionState::Payable && $fee ? ' до '.$fee->due_at->translatedFormat('j M') : '' }}{{ $state === CommissionState::Paid && $fee?->paid_at ? ' '.$fee->paid_at->translatedFormat('j M Y') : '' }}</x-ui.pill>
                </div>
                @if ($state === CommissionState::Withheld)<p class="mt-3 text-ink-muted">Удержано из счёта: к оплате была цена за вычетом вознаграждения</p>@endif
                @if ($fee && $fee->payments->isNotEmpty())
                    <div class="mt-4 flex flex-col divide-y divide-line/40 border-t border-line/40">
                        @foreach ($fee->payments as $p)
                            <div class="flex items-center gap-3 py-2">
                                <span class="min-w-0 flex-1">Выплачено@if ($p->ref), № {{ $p->ref }}@endif @if ($p->slip())<a href="/account/money/invoices/{{ $fee->id }}/payments/{{ $p->id }}/slip" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>платёжка</a>@endif</span>
                                <span class="nums text-sm text-ink-muted">{{ $p->paid_at->translatedFormat('j M Y') }}</span>
                                <span class="nums font-medium text-accent-text">{{ Money::rub($p->amount) }}</span>
                            </div>
                        @endforeach
                        @if ($fee->remaining() > 0)<div class="flex items-baseline justify-between gap-3 py-2"><span>Осталось выплатить</span><span class="nums font-semibold">{{ Money::rub($fee->remaining()) }}</span></div>@endif
                    </div>
                @endif
            </div>
            <div class="box">
                <h2 class="text-xl">Счета</h2>
                <dl class="mt-3 grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
                    <dt class="text-sm text-ink-dim">Цена подтверждения</dt><dd class="nums text-right font-medium">{{ Money::rub($deal->amount) }}</dd>
                </dl>
                <div class="mt-3 flex flex-col gap-2">
                    @foreach ($invoices as $i)
                        <a href="/account/money/invoices/{{ $i->id }}" class="row !py-3">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">Счёт {{ $i->label() }}<span class="text-ink-muted"> {{ $i->party->name }}</span></span>
                                <span class="row-sub"><x-billing.light :invoice="$i"/>@if ($i->state === InvoiceState::Issued)<span class="tag nums">до {{ $i->due_at->translatedFormat('j M') }}</span>@endif @if ($i->paid > 0 && $i->remaining() > 0)<span class="tag nums">оплачено {{ Money::rub($i->paid) }}</span>@endif</span>
                            </span>
                            <span class="nums shrink-0 font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
        <aside class="lg:self-start">
            <div class="box overflow-hidden !p-0">
                <a href="/account/deals/{{ $deal->id }}" class="group block">
                    @if ($photo = $offer->mainPhoto())<x-offer.photo :media="$photo" sizes="(min-width: 1024px) 320px, 100vw" class="aspect-[4/3] w-full object-cover"/>@endif
                    <div class="p-5"><p class="font-medium group-hover:text-accent-text">{{ $offer->titleWithYear() }}</p><p class="row-sub mt-1.5"><span class="tag nums">№ {{ $offer->number }}</span><span class="tag">сделка</span></p></div>
                </a>
            </div>
        </aside>
    </div>
</x-ui.cabinet>
