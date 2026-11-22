{{-- «Оплаты» → человек (18.11.2026; с 22.11.2026 — все его сделки, владелец: «отображались ВСЕ сделки, столбец „Статус“ —
     этап», «„Оплаченные“ — тут же, за всю историю, бесконечной лентой»). Сверху — разделы человека пилюлями с числом открытого,
     ниже тулбар: лупа, чип «Статус» (`MoneyStage`, по умолчанию — после «Беру в работу») и «Создать ссылку» с ним менеджером.
     Таблица (`x-money.person-row`, два этажа, как «Предложения») группами с суммой справа: «Не выставлены» (и сделки, где счёта ещё
     нет), «Выставлены» (дольше ждущее выше), «Оплачены» — порциями по сотне без суммы (накопленных сумм не показываем). Строка —
     окно машины рядом, ручная ссылка и счёт без машины — его карточка. --}}
@php
    use App\Http\Admin\MoneyController; use App\Support\Money;
    $payout = $tab === 'payouts';
    $name = $user?->name ?? ($payout ? 'Другие получатели' : 'Без менеджера');
    $columns = MoneyController::personTable($payout);
    $start = $portion->currentPage() === 1;
    $unsent = $rows->filter(fn ($r) => $r->group() === 'unsent')->sortBy(fn ($r) => [$r->invoices->isEmpty() ? 1 : 0, $r->deal?->created_at?->timestamp ?? 0])->values();
    $issued = $rows->filter(fn ($r) => $r->group() === 'issued')->sortBy(fn ($r) => $r->since()?->timestamp ?? PHP_INT_MAX)->values();
    // Сумма у заголовка — только когда есть что (у сделок, где счёта ещё нет, её нет).
    $sum = fn ($v) => $v > 0 ? '<span class="table-group-aside"><span class="nums">'.e(Money::rub($v)).'</span></span>' : '';
    $paidCount = $portion->total() + ($start ? $paid->filter(fn ($r) => $r->service && ! $r->service->isOwed())->count() : 0);
    $empty = $rows->isEmpty() && $paid->isEmpty();
    $creates = ! $payout && auth()->user()->isAdmin() && app(\App\Billing\Acquiring\Gateway::class)->configured();
    $pay = \App\Billing\PayView::here();
@endphp
<x-ui.shell :title="$name" :detail="$detail">
    {{-- XCAR Pay (22.11.2026): шапки нет — аватар с меню в строке заголовка, как у XCAR Mail. --}}
    @if ($pay)<x-slot:actions class="!w-auto ml-auto"><x-ui.me-menu/></x-slot:actions>@endif
    {{-- Заработок менеджера за месяц (22.11.2026) — тот же кусок, что у него в «Оплатах»: начальник видит, сколько он заработал. --}}
    @if ($user && ! $payout)<x-money.earned :user="$user" class="mb-3"/>@endif
    {{-- Разделы человека — пилюлями самого тулбара (22.11.2026, владелец: отдельный ряд пилюль над рядом лупы и чипа — «вёрстка
         ужасно выглядит»): на ПК одной строкой с лупой и «Статусом», на телефоне пилюли — своей строкой сверху. --}}
    <x-ui.toolbar name="money-person" :pills="$tabs" :pill="$tab" pill-param="tab" pill-home="fees" :pill-drop="['peek', 'q']" :counts="array_filter($counts)"
        :facets="$payout ? null : $facets" search="Номер, марка, VIN" search-target="#money-person-list">
        @if ($creates)
            <x-slot:actions>@include('admin.money.service-sheet', ['manager' => $user])</x-slot:actions>
        @endif
    </x-ui.toolbar>

    <div id="money-person-list" data-detail-shape="plain">
        @if ($empty)
            <x-ui.empty class="mt-6">{{ match (true) { $q !== '' => 'Ничего не нашлось', $payout => 'Выплат нет', $facets->active() => 'По фильтру ничего', default => 'Сделок нет' } }}</x-ui.empty>
        @else
            <x-ui.table id="money-person" class="mt-4" :table="$columns" :portion="$portion">
                <x-slot:head><x-table.head :table="$columns"/></x-slot:head>
                @if ($start && $unsent->isNotEmpty())
                    <x-table.group :table="$columns" key="unsent" title="Не выставлены" :count="$unsent->count()">{!! $sum($unsent->sum(fn ($r) => $r->amount())) !!}</x-table.group>
                    @foreach ($unsent as $r)<x-money.person-row :table="$columns" :row="$r" :tab="$tab" :tried="$tried"/>@endforeach
                @endif
                @if ($start && $issued->isNotEmpty())
                    <x-table.group :table="$columns" key="issued" :title="$payout ? 'К выплате' : 'Выставлены'" :count="$issued->count()">{!! $sum($issued->sum(fn ($r) => $r->amount())) !!}</x-table.group>
                    @foreach ($issued as $r)<x-money.person-row :table="$columns" :row="$r" :tab="$tab" :tried="$tried"/>@endforeach
                @endif
                @if ($paid->isNotEmpty())
                    {{-- Заголовок — в начале каждой порции: продолжение группы `endless_controller` снимает сам. --}}
                    <x-table.group :table="$columns" key="paid" :title="$payout ? 'Выплачены' : 'Оплачены'" :count="$paidCount"/>
                    @foreach ($paid as $r)<x-money.person-row :table="$columns" :row="$r" :tab="$tab" :attempts="$attempts"/>@endforeach
                @endif
            </x-ui.table>
        @endif
    </div>
    {{-- Окно писем машины: письмо в окне сделки открывается поверх (22.11.2026: «жму на письмо в окошке — не открывается»). В XCAR Pay
         окна машины нет — машина шторкой со страницей CRM, письма — там. --}}
    @unless ($pay)<x-mail.window/>@endunless
</x-ui.shell>
