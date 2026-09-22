{{-- Деньги менеджера одним экраном: реквизиты строкой-действием, если их нет; счета к оплате; вознаграждение по
     сделкам с чипом «К выплате»; последние операции; документы за период; реквизиты. Пустые секции не рисуются,
     плиток-итогов нет — суммы в чипах и в строках. --}}
@php
    use App\Support\Money; use App\Offers\CommissionState;
    $needDetails = ! $party->filled() || ! $party->payoutReady();
    $empty = $toPay->isEmpty() && $deals->isEmpty() && $recent->isEmpty();
@endphp
<x-ui.cabinet title="Деньги">
    @if ($needDetails && ! $empty)
        <a href="/account/money/details" class="row {{ $payable > 0 ? 'bg-urgent-soft' : '' }}">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full {{ $payable > 0 ? 'bg-urgent text-white' : 'bg-surface-3 text-ink' }}"><x-ui.icon name="file" class="size-5"/></span>
            <span class="min-w-0 flex-1">
                <span class="block font-medium {{ $payable > 0 ? 'text-urgent' : '' }}">Реквизиты для выплат не указаны</span>
                @if ($payable > 0)<span class="row-sub"><span class="tag nums">к выплате {{ Money::rub($payable) }}</span></span>@endif
            </span>
            <x-ui.icon name="chevron-right" class="size-5 shrink-0 {{ $payable > 0 ? 'text-urgent' : 'text-ink-dim' }}"/>
        </a>
    @endif

    @if ($toPay->isNotEmpty())
        <section>
            <h2 class="text-xl">К оплате <span class="nums text-ink-dim">{{ $toPay->count() }}</span></h2>
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($toPay as $i)
                    @php $offer = $i->deal?->offer; $claimed = $i->claimed(); $mine = $i->party_id === auth()->user()->party_id; @endphp
                    <a href="/account/money/invoices/{{ $i->id }}" class="row items-start">
                        @if ($offer)<span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>@endif
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">Счёт {{ $i->label() }}</span>
                            <span class="row-sub">
                                @if ($offer)<span class="tag truncate">{{ $offer->titleWithYear() }}</span>@endif
                                <x-billing.light :invoice="$i"/>
                                @if (! $mine)<span class="tag">платит {{ $i->party->name }}</span>@endif
                                @if ($claimed > 0)<span class="tag text-urgent">сообщили {{ Money::rub($claimed) }}, ждёт подтверждения</span>@endif
                                @if ($i->isPartial())<span class="tag nums">из {{ Money::rub($i->total) }}</span>@endif
                            </span>
                        </span>
                        <span class="nums shrink-0 font-semibold">{{ Money::rub($i->remaining()) }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($deals->isNotEmpty())
        <section>
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="text-xl">Вознаграждение</h2>
                @if ($payable > 0)<span class="chip nums bg-accent text-white">К выплате {{ Money::rub($payable) }}</span>@endif
            </div>
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($deals as $deal)
                    @php $offer = $deal->offer; $state = $deal->commissionState(); $fee = $deal->agentFee; @endphp
                    <a href="/account/money/deals/{{ $deal->id }}" class="row items-start">
                        <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
                            <span class="row-sub"><span class="tag nums">№ {{ $offer->number }}</span>
                                <x-ui.pill :tone="$state->tone()" class="!min-h-0 !py-1 text-xs">{{ mb_strtolower($state->label()) }}{{ $state === CommissionState::Payable && $fee ? ' до '.$fee->due_at->translatedFormat('j M') : '' }}{{ $state === CommissionState::Paid && $fee?->paid_at ? ' '.$fee->paid_at->translatedFormat('j M') : '' }}</x-ui.pill>
                            </span>
                        </span>
                        <span class="nums shrink-0 font-semibold">{{ Money::rub($deal->commission) }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($recent->isNotEmpty())
        <section>
            <h2 class="text-xl">Последнее</h2>
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($recent as $r)
                    @include('cabinet.money.op', ['r' => $r])
                @endforeach
                @if ($total > $recent->count())
                    <a href="/account/money/history" class="row"><span class="min-w-0 flex-1 font-medium">Вся история</span><span class="nums text-sm text-ink-dim">{{ $total }}</span><x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/></a>
                @endif
            </div>
        </section>
    @endif

    @if ($empty)
        <x-ui.empty href="/account/deals" link="К сделкам">Счетов и выплат пока нет</x-ui.empty>
    @else
        <section data-controller="sheet">
            <h2 class="text-xl">Документы</h2>
            <div class="mt-4 flex flex-col gap-2">
                <button type="button" class="row w-full text-left" data-action="sheet#open">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="file" class="size-5"/></span>
                    <span class="min-w-0 flex-1 font-medium">Акт сверки и выгрузка за период</span>
                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                </button>
                <x-ui.sheet id="period" title="За период">
                    {{-- Одна форма, две кнопки: PDF акта и Excel со сделками; на телефоне — во встроенный браузер. --}}
                    <form method="get" action="/account/money/statement" class="flex flex-col gap-3" data-turbo="false" data-controller="file" data-action="submit->file#share">
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.field name="from" label="С" type="date" :value="$month->toDateString()"/>
                            <x-ui.field name="to" label="По" type="date" :value="now()->toDateString()"/>
                        </div>
                        <x-ui.button block data-file-any>Акт сверки, PDF</x-ui.button>
                        <x-ui.button block variant="secondary" formaction="/account/money/export" data-file-any>Сделки, Excel</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
        </section>
    @endif

    @unless ($needDetails && ! $empty)
    <section>
        <a href="/account/money/details" class="row">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="user" class="size-5"/></span>
            <span class="min-w-0 flex-1">
                <span class="block font-medium">Реквизиты</span>
                <span class="row-sub">@if ($party->filled())<span class="tag">{{ $party->kind->label() }}</span>@if ($party->bankDetails())<span class="tag truncate">{{ $party->bank_name ?: 'карта' }}</span>@endif @else<span class="tag">не указаны</span>@endif</span>
            </span>
            <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
        </a>
    </section>
    @endunless
</x-ui.cabinet>
