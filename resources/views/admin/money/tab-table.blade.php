{{-- Вкладка «Оплат» таблицей — вид по умолчанию (07.10.2026, владелец: «мне гораздо удобнее табличные», строки низкие,
     чтобы помещалось больше). Одна `x-ui.table` на вкладку, группы — липкие `x-table.group`: «Проверить» (менеджер пишет,
     что оплатил), «Ждём» / «Выплатить», «Оплачено» / «Выплачено». Столбцы — `MoneyController::table`, строка —
     `x-money.table-row`; фразы — `MoneyRows`, строка открывает карточку рядом. --}}
@php
    use App\Billing\PaymentSource; use App\Http\Admin\MoneyRows as R; use App\Support\Money;
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

        {{-- Проверить: менеджер пишет, что оплатил — «Поступило» в карточке. --}}
        @if ($s['claims']->isNotEmpty())
            <x-table.group :table="$columns" key="claims" title="Проверить" :count="$s['claims']->count()"/>
            @foreach ($s['claims'] as $p)
                <x-money.table-row :table="$columns" :tab="$key" :invoice="$p->invoice" :title="$p->invoice->party->name" tone="text-urgent"
                    :now="R::who($p->invoice).' пишет, что '.($p->source === PaymentSource::Cash ? 'отдал наличные' : 'оплатил').' '.$p->paid_at->translatedFormat('j M')"
                    :manager="$p->invoice->manager()" :amount="$p->amount"/>
            @endforeach
        @endif

        {{-- Ждём / Выплатить: открытые; по умолчанию сверху те, где по ссылке пытались и не вышло. --}}
        @if ($s['open']->isNotEmpty())
            <x-table.group :table="$columns" key="open" :title="$key === 'payouts' ? 'Выплатить' : 'Ждём'" :count="$s['open']->count()"/>
            @foreach ($s['open'] as $i)
                @php
                    $due = R::due($i);
                    $link = $s['tried']->contains($i->id) ? R::link($i) : null;
                    // Что сейчас — одно главное: не прошла по ссылке важнее срока; у выплаты без реквизитов — это.
                    [$now, $tone] = match (true) {
                        $key === 'payouts' && ! $i->party->payoutReady() => ['реквизитов нет', 'text-danger'],
                        (bool) $link => $link,
                        (bool) $due => $due,
                        default => ['срок '.$i->laterLabel(), ''],
                    };
                    $partial = $i->isPartial() ? 'оплачено '.Money::rub($i->total - $i->remaining()).' из '.Money::rub($i->total) : null;
                    $noReq = $key === 'payouts' && $due && $now === 'реквизитов нет';
                @endphp
                <x-money.table-row :table="$columns" :tab="$key" :invoice="$i" :title="$key === 'payouts' ? R::who($i) : $i->party->name" :now="$now" :tone="$tone"
                    :after="$partial ? [$partial, 'text-ink-muted'] : ($noReq ? $due : null)" :phone="$noReq ? $due : null"
                    :manager="R::via($i)" :amount="$i->remaining()"/>
            @endforeach
        @endif

        {{-- Оплачено / Выплачено: по умолчанию свежие сверху, как заплатили. --}}
        @if ($paidItems->isNotEmpty())
            <x-table.group :table="$columns" key="paid" :title="$key === 'payouts' ? 'Выплачено' : 'Оплачено'" :count="$paid instanceof \Illuminate\Support\Collection ? $paidItems->count() : $paid->total()"/>
            @foreach ($paidItems as $i)
                <x-money.table-row :table="$columns" :tab="$key" :invoice="$i" :title="$key === 'payouts' ? R::who($i) : $i->party->name"
                    :now="$key === 'payouts' ? R::paidOut($i) : R::how($i, $attempts)" tone="text-ink-muted" :manager="R::via($i)" :amount="$i->total" amount-tone="text-open"/>
            @endforeach
        @endif
    </x-ui.table>
    @if (! $paid instanceof \Illuminate\Support\Collection && $paid->hasPages())<div class="mt-8"><x-ui.pager :of="$paid"/></div>@endif
    @endunless
</section>
@endif
