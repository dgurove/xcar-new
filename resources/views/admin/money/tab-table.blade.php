{{-- Вкладка «Оплат» таблицей — вид по умолчанию (07.10.2026, владелец: «мне гораздо удобнее табличные», строки низкие,
     чтобы помещалось больше). Одна `x-ui.table` на вкладку, группы — липкие `x-table.group`: «Проверить» (менеджер пишет,
     что оплатил), «Просрочено» и «Ждём» с суммой (у «Мы должны» — группа на получателя); оплаченное — только с чипом
     «Оплаченные» (группа «Оплачено» / «Выплачено», без суммы: накопленное не показываем). Столбцы — `MoneyController::table`, строка —
     `x-money.table-row`; фразы — `MoneyRows`, строка открывает карточку рядом. --}}
@php
    use App\Billing\PartyName; use App\Billing\PaymentSource; use App\Http\Admin\MoneyRows as R; use App\Support\Money;
    $paid = $s['paid'];
    $paidItems = $paid instanceof \Illuminate\Support\Collection ? $paid : collect($paid->items());
    $empty = $s['claims']->isEmpty() && $s['open']->isEmpty() && $paidItems->isEmpty();
    $columns = \App\Http\Admin\MoneyController::table($key);
@endphp
@if (! ($titled && $empty))
<section class="mt-4" data-search-group>
    @if ($titled)<h2 class="list-head">{{ \App\Http\Admin\MoneyController::TABS[$key] }}</h2>@endif
    @unless ($empty)
    <x-ui.table :id="'money-'.$key" class="mt-2" :table="$columns">
        <x-slot:head><x-table.head :table="$columns" :sort="$titled ? null : $sort"/></x-slot:head>

        {{-- Проверить: менеджер пишет, что оплатил — «Поступило» в карточке. Сумма у заголовка — сколько пишут, что заплатили. --}}
        @if ($s['claims']->isNotEmpty())
            <x-table.group :table="$columns" key="claims" title="Проверить" :count="$s['claims']->count()">
                <span class="table-group-aside"><span class="nums">{{ Money::rub($s['claims']->sum('amount')) }}</span></span>
            </x-table.group>
            @foreach ($s['claims'] as $p)
                <x-money.table-row :table="$columns" :tab="$key" :invoice="$p->invoice" :title="PartyName::short($p->invoice->party->name)" tone="text-urgent"
                    :now="($p->source === PaymentSource::Cash ? 'отдал наличные ' : 'пишет, что оплатил ').\App\Support\Ago::day($p->paid_at)"
                    :manager="$p->invoice->manager()" :amount="$p->amount"/>
            @endforeach
        @endif

        {{-- Нам должны (17.11.2026): «Просрочено» и «Ждём» — каждая с суммой остатка у заголовка: сколько нам должны и сколько
             из этого просрочено видно сразу, без счёта строк. Статус — срок; частичная — «оплачено N %», ссылка — её слово
             хвостом (`MoneyRows::linkWord`). Сверху в группе — где по ссылке пытались и не вышло. --}}
        @if ($s['open']->isNotEmpty() && $key !== 'payouts')
            @foreach (collect(['overdue' => 'Просрочено', 'open' => 'Ждём'])->map(fn ($t, $g) => [$t, $s['open']->filter(fn ($i) => $i->isOverdue() === ($g === 'overdue'))])->filter(fn ($g) => $g[1]->isNotEmpty()) as $group => [$groupTitle, $bills])
                <x-table.group :table="$columns" :key="$group" :title="$groupTitle" :count="$bills->count()">
                    <span class="table-group-aside"><span class="nums">{{ Money::rub($bills->sum(fn ($b) => $b->remaining())) }}</span></span>
                </x-table.group>
                @foreach ($bills as $i)
                    @php [$now, $tone] = R::due($i) ?? ['срок '.$i->laterLabel(), '']; @endphp
                    <x-money.table-row :table="$columns" :tab="$key" :invoice="$i" :title="PartyName::short($i->party->name)" :now="$now" :tone="$tone" :link="R::linkWord($i)"
                        :manager="R::via($i)" :amount="$i->remaining()" :paid="$i->isPartial() ? 1 - $i->remaining() / $i->total : null"/>
                @endforeach
            @endforeach
        @endif

        {{-- Мы должны (17.11.2026): открытые — группой на получателя (кому сколько перевести): имя, сколько счетов, «реквизитов
             нет» один раз и сумма; строка — за что и срок. Порядок групп — по самому раннему сроку. --}}
        @if ($s['open']->isNotEmpty() && $key === 'payouts')
            @foreach ($s['open']->groupBy('party_id') as $party => $bills)
                @php $first = $bills->first(); @endphp
                <x-table.group :table="$columns" :key="'to-'.$party" :title="PartyName::short(R::who($first))" :count="$bills->count()">
                    <span class="table-group-aside">@unless ($first->party->payoutReady())<span class="text-danger">реквизитов нет</span>@endunless<span class="nums">{{ Money::rub($bills->sum(fn ($b) => $b->remaining())) }}</span></span>
                </x-table.group>
                @foreach ($bills as $i)
                    @php [$now, $tone] = R::due($i) ?? ['срок '.$i->laterLabel(), '']; @endphp
                    <x-money.table-row :table="$columns" :tab="$key" :invoice="$i" :title="R::payoutKind($i)" :now="$now" :tone="$tone"
                        :amount="$i->remaining()" :paid="$i->isPartial() ? 1 - $i->remaining() / $i->total : null"/>
                @endforeach
            @endforeach
        @endif

        {{-- Оплачено / Выплачено: по умолчанию свежие сверху, как заплатили. --}}
        @if ($paidItems->isNotEmpty())
            <x-table.group :table="$columns" key="paid" :title="$key === 'payouts' ? 'Выплачено' : 'Оплачено'" :count="$paid instanceof \Illuminate\Support\Collection ? $paidItems->count() : $paid->total()"/>
            @foreach ($paidItems as $i)
                <x-money.table-row :table="$columns" :tab="$key" :invoice="$i" :title="PartyName::short($key === 'payouts' ? R::who($i) : $i->party->name)"
                    :now="$key === 'payouts' ? R::paidOut($i) : R::how($i, $attempts)" tone="text-ink-muted" :manager="R::via($i)" :amount="$i->total" amount-tone="text-open"/>
            @endforeach
        @endif
    </x-ui.table>
    @if (! $paid instanceof \Illuminate\Support\Collection && $paid->hasPages())<div class="mt-8"><x-ui.pager :of="$paid"/></div>@endif
    @endunless
</section>
@endif
