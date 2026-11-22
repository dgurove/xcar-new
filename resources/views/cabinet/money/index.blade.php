{{-- «Оплаты» менеджера — один экран на xcar.ru (кабинет) и XCAR Pay (22.11.2026, начальник: «чтобы менеджеры видели, что должны по
     комиссиям и по ДКП и сколько заработали»; владелец: «один экран везде»). Как страница человека в «Оплатах» CRM: разделы
     «Комиссии · ДКП» крупными заголовками с числом к оплате («Выплаты» — его перевозки, когда есть), заработок за месяц
     (`x-money.earned`), таблица группами «К оплате» (дольше ждущее выше, сумма у заголовка) · «Готовим счёт» (без суммы: счёт ещё
     проверяют) · «Оплачены» — лентой порциями, без суммы (накопленного не показываем). Строка — `x-money.own-row`, ведёт в сделку.
     Реквизиты, акт сверки и Excel — в «···» справа от разделов. До 22.11.2026 здесь были пилюли «Все · Оплатить · Ждут выплаты ·
     Закрытые» строками `x-money.deal-row`; они остались у «Выплат». --}}
@php
    use App\Support\Money;
    $pay = \App\Billing\PayView::here();
    $payouts = $tab === 'payouts';
    $start = ! $portion || $portion->currentPage() === 1;
    $issued = $rows->filter(fn ($r) => $r->group() === 'issued')->sortBy(fn ($r) => $r->since()?->timestamp ?? PHP_INT_MAX)->values();
    $waiting = $rows->filter(fn ($r) => $r->group() === 'unsent')->sortByDesc(fn ($r) => $r->deal?->created_at?->timestamp ?? 0)->values();
    $sum = fn ($v) => $v > 0 ? '<span class="table-group-aside"><span class="nums">'.e(Money::rub($v)).'</span></span>' : '';
    $empty = $rows->isEmpty() && $paid->isEmpty();
    $needDetails = $payouts && (! $party->filled() || ! $party->payoutReady());
@endphp
<x-dynamic-component :component="$pay ? 'ui.shell' : 'ui.cabinet'" title="Оплаты" :heading="false">
    <div @class(['title-row', 'title-row--app' => $pay])>
        <x-ui.big-tabs label="Оплаты" :tabs="$tabs" :current="$tab" :href="fn ($k) => '/account/money?tab='.$k" :badges="$counts"/>
        <div class="title-actions">
            <div class="contents" data-controller="sheet">
                <button type="button" class="more-pill more-pill--light" data-action="sheet#open" aria-label="Реквизиты и документы"><x-ui.icon name="more" class="size-[22px]"/></button>
                <x-ui.sheet id="money-more" title="Оплаты">
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
                            <div class="fields">
                                <x-ui.field name="from" label="С" type="date" :value="now()->startOfMonth()->toDateString()"/>
                                <x-ui.field name="to" label="По" type="date" :value="now()->toDateString()"/>
                            </div>
                            <x-ui.doc :doc="['url' => '/account/money/statement?'.$period, 'type' => 'pdf', 'name' => 'akt-sverki.pdf', 'label' => 'Акт сверки']" class="btn btn-quiet btn-block" data-action="doc-query#check">Акт сверки, PDF</x-ui.doc>
                            <x-ui.doc :doc="['url' => '/account/money/export?'.$period, 'type' => 'sheet', 'name' => 'sdelki.xlsx', 'label' => 'Сделки, Excel']" class="btn btn-quiet btn-block" data-action="doc-query#check">Сделки, Excel</x-ui.doc>
                        </form>
                    </div>
                </x-ui.sheet>
            </div>
            {{-- XCAR Pay: шапки нет — аватар с меню в строке заголовка, как у XCAR Mail. --}}
            @if ($pay)<x-ui.me-menu/>@endif
        </div>
    </div>
    <x-money.earned :user="$me" class="mb-4"/>

    @if ($needDetails)
        <div class="list mb-4">
            <a href="/account/money/details" class="row">
                <x-ui.row-icon name="user" tone="urgent" size="s"/>
                <span class="min-w-0 flex-1 text-urgent">Реквизиты для выплат не указаны</span>
                <x-ui.chevron/>
            </a>
        </div>
    @endif

    @if ($payouts)
        {{-- Выплаты за перевозки — прежними строками расчёта: их у менеджеров единицы. --}}
        <div class="list">
            @foreach ($transports as $t)<x-money.deal-row :deal="$t" :href="\App\Support\Surface::Logistics->url('/transports/'.$t->id)"/>@endforeach
        </div>
    @elseif ($empty)
        <x-ui.empty>{{ $tab === 'dkp' ? 'Счетов по ДКП пока нет' : 'Счетов за подбор пока нет' }}</x-ui.empty>
    @else
        <div @unless ($pay) data-controller="row-go" data-action="click->row-go#go" @endunless>
            <x-ui.table id="account-money" :table="$table" :portion="$portion">
                <x-slot:head><x-table.head :table="$table"/></x-slot:head>
                @if ($start && $issued->isNotEmpty())
                    <x-table.group :table="$table" key="issued" title="К оплате" :count="$issued->count()">{!! $sum($issued->sum(fn ($r) => $r->amount())) !!}</x-table.group>
                    @foreach ($issued as $r)<x-money.own-row :table="$table" :row="$r"/>@endforeach
                @endif
                @if ($start && $waiting->isNotEmpty())
                    <x-table.group :table="$table" key="waiting" title="Готовим счёт" :count="$waiting->count()"/>
                    @foreach ($waiting as $r)<x-money.own-row :table="$table" :row="$r"/>@endforeach
                @endif
                @if ($paid->isNotEmpty())
                    {{-- Заголовок — в начале каждой порции: продолжение группы `endless_controller` снимает сам. --}}
                    <x-table.group :table="$table" key="paid" title="Оплачены" :count="$portion->total()"/>
                    @foreach ($paid as $r)<x-money.own-row :table="$table" :row="$r" :attempts="$attempts"/>@endforeach
                @endif
            </x-ui.table>
        </div>
    @endif
</x-dynamic-component>
