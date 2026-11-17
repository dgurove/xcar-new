{{-- «Оплаты» → человек (18.11.2026, владелец: «внутри все оплаты по этому менеджеру и в том числе то, что надо ему отправить»;
     «внутри надо как таблицу, а не строками»). Сверху — разделы этого человека пилюлями с числом открытого; ниже одна таблица
     (`MoneyController::personTable`, строка — `x-money.person-row`) группами: «Отправить» (сделка на шаге оплаты, менеджер ещё не
     видит — «Отправить» или «Уже оплачено» наличными), «Сообщил об оплате» («Поступила» / «Не поступила»), «Ждём оплату» (ссылка —
     «Скопировать» / «Отправить»), у выплат — «Выплатить»; последней — «Оплачено за 30 дней». Строка — окно машины рядом. --}}
@php
    use App\Http\Admin\MoneyController; use App\Http\Admin\MoneyRows as R; use App\Support\Money; use App\Billing\PaymentSource;
    $payout = $tab === 'payouts';
    $name = $user?->name ?? ($payout ? 'Другие получатели' : 'Без менеджера');
    // У ДКП — чей счёт: ПРАЙМ или Р.О.С.
    $seller = fn ($i) => $tab === 'dkp' ? $i->seller->short() : null;
    $empty = $send->isEmpty() && $claimed->isEmpty() && $waiting->isEmpty() && $paid->isEmpty();
    $columns = MoneyController::personTable();
    $sumHead = fn ($rows, $sum) => $rows->count() > 1 ? '<span class="table-group-aside"><span class="nums">'.e(Money::rub($sum)).'</span></span>' : '';
@endphp
<x-ui.shell :title="$name" :detail="$detail">
    <x-ui.pills>
        @foreach (MoneyController::TABS as $k => $t)
            <x-ui.pill :href="MoneyController::personUrl($user, $k)" :current="$k === $tab" data-turbo-action="replace">{{ $t }}@if ($counts[$k])<span class="nums ml-1 opacity-60">{{ $counts[$k] }}</span>@endif</x-ui.pill>
        @endforeach
    </x-ui.pills>

    @if ($empty)
        <x-ui.empty class="mt-6">{{ $payout ? 'Выплачивать нечего' : 'Открытых оплат нет' }}</x-ui.empty>
    @else
    <x-ui.table id="money-person" class="mt-4" :table="$columns">
        <x-slot:head><x-table.head :table="$columns"/></x-slot:head>

        @if ($send->isNotEmpty())
            <x-table.group :table="$columns" key="send" title="Отправить" :count="$send->count()">{!! $sumHead($send, $send->sum(fn ($i) => $i->remaining())) !!}</x-table.group>
            @foreach ($send as $i)
                @php $left = round($i->remaining(), 2); @endphp
                <x-money.person-row :table="$columns" :invoice="$i" :tab="$tab" :now="$seller($i)" :amount="$left">
                    @if ($i->deal_id)
                        <form method="post" action="/work/deals/{{ $i->deal_id }}/invoices/{{ $i->id }}/show">@csrf<button type="submit" class="btn btn-accent btn-s">Отправить</button></form>
                    @endif
                    <form method="post" action="/work/money/invoices/{{ $i->id }}/payments" data-turbo-confirm="Отметить {{ Money::rub($left) }} наличными?" data-turbo-confirm-label="Уже оплачено">
                        @csrf
                        <input type="hidden" name="amount" value="{{ number_format($left, 2, '.', '') }}">
                        <input type="hidden" name="source" value="{{ PaymentSource::Cash->value }}">
                        <input type="hidden" name="paid_at" value="{{ now()->toDateString() }}">
                        <button type="submit" class="btn btn-quiet btn-s">Уже оплачено</button>
                    </form>
                </x-money.person-row>
            @endforeach
        @endif

        @if ($claimed->isNotEmpty())
            @php $claims = $claimed->flatMap(fn ($i) => $i->claims->map(fn ($p) => [$i, $p])); @endphp
            <x-table.group :table="$columns" key="claims" title="Сообщил об оплате" :count="$claims->count()">{!! $sumHead($claims, $claims->sum(fn ($c) => $c[1]->amount)) !!}</x-table.group>
            @foreach ($claims as [$i, $p])
                <x-money.person-row :table="$columns" :invoice="$i" :tab="$tab" tone="text-urgent" :amount="$p->amount"
                    :now="($p->source === PaymentSource::Cash ? 'наличными ' : 'переводом ').\App\Support\Ago::day($p->paid_at)">
                    <form method="post" action="/work/payments/{{ $p->id }}/confirm" data-turbo-confirm="Поступила {{ Money::rub($p->amount) }}?">@csrf<button type="submit" class="btn btn-accent btn-s">Поступила</button></form>
                    <form method="post" action="/work/payments/{{ $p->id }}/reject" data-turbo-confirm="Не поступила {{ Money::rub($p->amount) }}?">@csrf<button type="submit" class="btn btn-quiet btn-s">Не поступила</button></form>
                </x-money.person-row>
            @endforeach
        @endif

        @if ($waiting->isNotEmpty())
            <x-table.group :table="$columns" key="waiting" :title="$payout ? 'Выплатить' : 'Ждём оплату'" :count="$waiting->count()">{!! $sumHead($waiting, $waiting->sum(fn ($i) => $i->remaining())) !!}</x-table.group>
            @foreach ($waiting as $i)
                @php
                    // Срок — только датой; без даты слов нет.
                    [$now, $tone] = R::due($i) ?? [null, ''];
                    $word = $tried->has($i->id) ? R::linkWord($i) : null;
                    $link = $payout ? null : $i->openLink();
                @endphp
                <x-money.person-row :table="$columns" :invoice="$i" :tab="$tab" :now="implode(', ', array_filter([$payout ? R::payoutKind($i) : $seller($i), $word[0] ?? $now]))" :tone="$word[1] ?? $tone" :amount="$i->remaining()">
                    @if ($payout)
                        <span data-controller="sheet" class="contents">
                            <button type="button" class="btn btn-accent btn-s" data-action="sheet#open">Выплатить</button>
                            <x-ui.sheet :id="'pay-'.$i->id" :title="'Выплата '.$i->party->name"><x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" sheet/></x-ui.sheet>
                        </span>
                    @elseif ($link)
                        <x-ui.share-link variant="buttons" :url="$link->url()" :title="$link->title()" :text="$link->message()" done="Ссылка на оплату в буфере"/>
                    @endif
                </x-money.person-row>
            @endforeach
        @endif

        @if ($paid->isNotEmpty())
            <x-table.group :table="$columns" key="paid" :title="($payout ? 'Выплачено' : 'Оплачено').' за 30 дней'" :count="$paid->count()"/>
            @foreach ($paid as $i)
                <x-money.person-row :table="$columns" :invoice="$i" :tab="$tab" tone="text-ink-muted" :amount="$i->total" amount-tone="text-open" :now="$payout ? R::paidOut($i) : R::how($i, $attempts)"/>
            @endforeach
        @endif
    </x-ui.table>
    @endif
</x-ui.shell>
