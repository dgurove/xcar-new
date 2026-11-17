{{-- «Оплаты» → человек (18.11.2026, владелец: «внутри все оплаты по этому менеджеру и в том числе то, что надо ему отправить —
     такие же строки, только две кнопки „Отправить“ / „Уже оплачено“»; «по машинам, а не по плательщикам»). Сверху — разделы этого
     человека пилюлями с числом открытого; ниже группами (сумма — у заголовка группы): «Отправить» (счёт или ссылка ещё не у него — «Отправить»,
     или деньги уже отдал наличными — «Уже оплачено»), «Сообщил об оплате» («Поступила» / «Не поступила»), «Ждём оплату»
     (просроченные и «ссылка не прошла» сверху), у выплат — «Выплатить»; последней — «Оплачено за 30 дней». Строка — окно машины
     рядом (`x-money.car-row`, `CarWindow`). --}}
@php
    use App\Http\Admin\MoneyController; use App\Http\Admin\MoneyRows as R; use App\Support\Money; use App\Billing\PaymentSource;
    $payout = $tab === 'payouts';
    $name = $user?->name ?? ($payout ? 'Другие получатели' : 'Без менеджера');
    // У ДКП под машиной — чей счёт: ПРАЙМ или Р.О.С.
    $seller = fn ($i) => $tab === 'dkp' ? $i->seller->short() : null;
    $empty = $send->isEmpty() && $claimed->isEmpty() && $waiting->isEmpty() && $paid->isEmpty();
@endphp
<x-ui.shell :title="$name" :detail="$detail">
    <div>
        <x-ui.pills>
            @foreach (MoneyController::TABS as $k => $t)
                <x-ui.pill :href="MoneyController::personUrl($user, $k)" :current="$k === $tab" data-turbo-action="replace">{{ $t }}@if ($counts[$k])<span class="nums ml-1 opacity-60">{{ $counts[$k] }}</span>@endif</x-ui.pill>
            @endforeach
        </x-ui.pills>
    </div>

    @if ($empty)
        <x-ui.empty class="mt-6">{{ $payout ? 'Выплачивать нечего' : 'Открытых оплат нет' }}</x-ui.empty>
    @endif

    {{-- Отправить: менеджер ещё не видит — отправить ему ссылку или счёт; уже отдал наличными — «Уже оплачено» на весь остаток. --}}
    @if ($send->isNotEmpty())
        <section class="mt-6">
            <h2 class="list-head">Отправить <span class="nums">{{ $send->count() }}</span>@if ($send->count() > 1)<span class="nums ml-auto">{{ Money::rub($send->sum(fn ($i) => $i->remaining())) }}</span>@endif</h2>
            <div class="list">
                @foreach ($send as $i)
                    @php $left = round($i->remaining(), 2); @endphp
                    <x-money.car-row :invoice="$i" :tab="$tab" :now="$seller($i)" :amount="$left">
                        <x-slot:acts>
                            @if ($i->deal_id)
                                <form method="post" action="/work/deals/{{ $i->deal_id }}/invoices/{{ $i->id }}/show">
                                    @csrf
                                    <button type="submit" class="btn btn-accent btn-s gap-2"><x-ui.icon name="send" class="size-4"/>Отправить</button>
                                </form>
                            @endif
                            <form method="post" action="/work/money/invoices/{{ $i->id }}/payments" data-turbo-confirm="Отметить {{ Money::rub($left) }} наличными?"
                                data-turbo-confirm-text="Менеджер отдал наличные — оплата встанет сегодняшним днём" data-turbo-confirm-label="Уже оплачено">
                                @csrf
                                <input type="hidden" name="amount" value="{{ number_format($left, 2, '.', '') }}">
                                <input type="hidden" name="source" value="{{ PaymentSource::Cash->value }}">
                                <input type="hidden" name="paid_at" value="{{ now()->toDateString() }}">
                                <button type="submit" class="btn btn-quiet btn-s">Уже оплачено</button>
                            </form>
                        </x-slot:acts>
                    </x-money.car-row>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Сообщил об оплате: проверить, пришли ли деньги. --}}
    @if ($claimed->isNotEmpty())
        <section class="mt-6">
            <h2 class="list-head">Сообщил об оплате <span class="nums">{{ $claimed->count() }}</span>@if ($claimed->count() > 1)<span class="nums ml-auto">{{ Money::rub($claimed->sum(fn ($i) => $i->claims->sum('amount'))) }}</span>@endif</h2>
            <div class="list">
                @foreach ($claimed as $i)
                    @foreach ($i->claims as $p)
                        <x-money.car-row :invoice="$i" :tab="$tab" tone="text-urgent" :amount="$p->amount"
                            :now="($p->source === PaymentSource::Cash ? 'отдал наличные ' : 'пишет, что оплатил ').\App\Support\Ago::day($p->paid_at)">
                            <x-slot:acts>
                                <form method="post" action="/work/payments/{{ $p->id }}/confirm" data-turbo-confirm="Поступила {{ Money::rub($p->amount) }}?">@csrf<button type="submit" class="btn btn-accent btn-s">Поступила</button></form>
                                <form method="post" action="/work/payments/{{ $p->id }}/reject" data-turbo-confirm="Не поступила {{ Money::rub($p->amount) }}?" data-turbo-confirm-text="Менеджеру придёт, что оплата не поступила">@csrf<button type="submit" class="btn btn-quiet btn-s">Не поступила</button></form>
                            </x-slot:acts>
                        </x-money.car-row>
                    @endforeach
                @endforeach
            </div>
        </section>
    @endif

    {{-- Ждём оплату / Выплатить: срок словом, «ссылка не прошла» и просроченные сверху. --}}
    @if ($waiting->isNotEmpty())
        <section class="mt-6">
            <h2 class="list-head">{{ $payout ? 'Выплатить' : 'Ждём оплату' }} <span class="nums">{{ $waiting->count() }}</span>@if ($waiting->count() > 1)<span class="nums ml-auto">{{ Money::rub($waiting->sum(fn ($i) => $i->remaining())) }}</span>@endif</h2>
            <div class="list">
                @foreach ($waiting as $i)
                    @php
                        // Срок — только датой; «срок вместе с оплатой за ТС» на каждой строке — лишнее.
                        [$now, $tone] = R::due($i) ?? [null, ''];
                        $word = $tried->has($i->id) ? R::linkWord($i) : null;
                        $what = $payout ? R::payoutKind($i) : $seller($i);
                    @endphp
                    <x-money.car-row :invoice="$i" :tab="$tab" :now="implode(', ', array_filter([$what, $word[0] ?? $now]))" :tone="$word[1] ?? $tone" :amount="$i->remaining()">
                        @if ($payout)
                            <x-slot:acts>
                                <div data-controller="sheet" class="contents">
                                    <button type="button" class="btn btn-accent btn-s" data-action="sheet#open">Выплатить</button>
                                    <x-ui.sheet :id="'pay-'.$i->id" :title="'Выплата '.$i->party->name"><x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" sheet/></x-ui.sheet>
                                </div>
                            </x-slot:acts>
                        @endif
                    </x-money.car-row>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Оплачено за 30 дней — как заплатили; накопленных сумм нет. --}}
    @if ($paid->isNotEmpty())
        <section class="mt-6">
            <h2 class="list-head">{{ $payout ? 'Выплачено' : 'Оплачено' }} за 30 дней</h2>
            <div class="list">
                @foreach ($paid as $i)
                    <x-money.car-row :invoice="$i" :tab="$tab" tone="text-ink-muted" :amount="$i->total" amount-tone="text-open" :now="$payout ? R::paidOut($i) : R::how($i, $attempts)"/>
                @endforeach
            </div>
        </section>
    @endif
</x-ui.shell>
