{{-- Деньги менеджера: пилюли пресетов с суммами (оплатить, к выплате), «Реквизиты не указаны» строкой над ними и
     список сделок-расчётов и машин гаража со счётом (их суммы — в тех же пилюлях) — одна строка, одна фраза, одно число; сроки — в строках сделок. Реквизиты и документы —
     в «···» справа от пилюль. Строки узкой колонкой и на компьютере. --}}
@php
    use App\Support\Money; use App\Billing\DealMoney;
    $needDetails = ! $party->filled() || ! $party->payoutReady();
    // Просрочка горит, пока о ней не сообщили оплатой.
    $overdue = $position['overdue'] > 0 && $position['claimed'] < $position['overdue'];
    // Сумма вместо числа сделок в пилюлях «Оплатить» и «Ждут выплаты» — ровно сумма строк пилюли (`ManagerLedger::sums`).
    $counts = array_merge($counts, array_filter(array_map(fn ($v) => $v > 0 ? Money::rub($v) : null, $sums)));
@endphp
<x-ui.cabinet title="Деньги">
    {{-- Узкая колонка — у строк; пилюли с суммами шире её, и на ПК «···» уходило бы за край ленты. --}}
    <div class="flex flex-col gap-6">

        @if ($needDetails && ($position['payout'] > 0 || $position['paid_out'] > 0))
            <div class="list max-w-[30rem]">
                <a href="/account/money/details" class="row">
                    <x-ui.row-icon name="user" tone="urgent" size="s"/>
                    <span class="min-w-0 flex-1 text-urgent">Реквизиты для выплат не указаны</span>
                    <x-ui.chevron/>
                </a>
            </div>
        @endif

            <x-ui.toolbar :sort="$sort" :pills="DealMoney::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" :tones="['pay' => $overdue ? 'pill-danger' : '']" name="money" action="/account/money">
                <x-slot:pillsExtra>
                    <div class="ml-1 shrink-0 self-center" data-controller="sheet">
                        <button type="button" class="btn btn-s btn-quiet btn-round" data-action="sheet#open" aria-label="Реквизиты и документы"><x-ui.icon name="more" class="size-5"/></button>
                        <x-ui.sheet id="money-more" title="Деньги">
                            <div class="list">
                                <a href="/account/money/details" class="row">
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-medium">Реквизиты</span>
                                        <span class="row-sub">@if ($party->filled())<span class="tag">{{ $party->kind->label() }}</span>@if ($party->bankDetails())<span class="tag truncate">{{ $party->bank_name ?: 'карта' }}</span>@endif @else<span class="tag">не указаны</span>@endif</span>
                                    </span>
                                    <x-ui.chevron/>
                                </a>
                                {{-- Акт сверки и Excel за период — шторкой документов; даты в адрес ссылок кладёт doc-query. --}}
                                @php $period = http_build_query(['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]); @endphp
                                <form method="get" action="/account/money/statement" class="mt-2 flex flex-col gap-3" data-turbo="false" data-controller="doc-query" data-action="change->doc-query#sync submit->doc-query#submit">
                                    <div class="grid grid-cols-2 gap-3">
                                        <x-ui.field name="from" label="С" type="date" :value="now()->startOfMonth()->toDateString()"/>
                                        <x-ui.field name="to" label="По" type="date" :value="now()->toDateString()"/>
                                    </div>
                                    <x-ui.doc :doc="['url' => '/account/money/statement?'.$period, 'type' => 'pdf', 'name' => 'akt-sverki.pdf', 'label' => 'Акт сверки']" class="btn btn-quiet btn-block" data-action="doc-query#check">Акт сверки, PDF</x-ui.doc>
                                    <x-ui.doc :doc="['url' => '/account/money/export?'.$period, 'type' => 'sheet', 'name' => 'sdelki.xlsx', 'label' => 'Сделки, Excel']" class="btn btn-quiet btn-block" data-action="doc-query#check">Сделки, Excel</x-ui.doc>
                                </form>
                            </div>
                        </x-ui.sheet>
                    </div>
                </x-slot:pillsExtra>
            </x-ui.toolbar>
            @if ($deals->isEmpty())
                <x-ui.empty class="max-w-[30rem]">{{ match ($preset) { 'pay' => 'Платить нечего', 'payout' => 'Выплат не ждёт', 'closed' => 'Закрытых ещё нет', default => 'Сделок с деньгами пока нет' } }}</x-ui.empty>
            @else
                <div class="list max-w-[30rem]">
                    @foreach ($deals as $row)
                        @if ($row instanceof \App\Garage\Car)
                            <x-money.deal-row :deal="$row" :href="$row->url()"/>
                        @else
                            <x-money.deal-row :deal="$row" :href="'/account/money/deals/'.$row->id"/>
                        @endif
                    @endforeach
                </div>
            @endif
    </div>
</x-ui.cabinet>
