{{-- «По ссылкам» таблицей — все ссылки на оплату одним списком, свежие сверху (09.10.2026, владелец: «один список из
     ссылок с сортировкой по дате создания, у всех подписана марка, модель и номер, и подписано, какие созданы сделкой, а
     какие вручную»). Строка — ссылка (`MoneyLinks`). На ПК: кто платит, ТС с номером серым, статус коротким словом цветом
     (`MoneyLinks::short`, полная фраза — в карточке), когда и кто взял, сумма. На телефоне — как строка банка: имя и
     сумма, под ними ТС и статус под суммой. По дате — группами по дням («Сегодня», «Вчера», «5 октября»), как история
     операций. Отменённые — только чипом «Отменённые», бледными. --}}
@php
    use App\Http\Admin\MoneyLinks as L;
    $items = $links instanceof \Illuminate\Support\Collection ? $links : collect($links->items());
    $sortable = $titled ? null : $sort;
    $columns = \App\Http\Admin\MoneyController::table('links');
    $days = ! $titled && ($sort?->key ?? 'created') === 'created' ? $items->groupBy(fn ($l) => L::day($l->created_at)) : collect(['' => $items]);
@endphp
@if (! ($titled && $items->isEmpty()))
<section class="mt-4" data-search-group>
    @if ($titled)<h2 class="list-head">{{ \App\Http\Admin\MoneyController::TABS['links'] }}</h2>@endif
    @if ($items->isNotEmpty())
    <x-ui.table id="money-links" class="mt-2" :table="$columns">
        <x-slot:head><x-table.head :table="$columns" :sort="$sortable"/></x-slot:head>
        @foreach ($days as $day => $group)
            @if ($day !== '')<x-table.group :table="$columns" :key="'d'.$group->first()->created_at->format('Ymd')" :title="$day"/>@endif
            @foreach ($group as $l)<x-money.link-row :table="$columns" :link="$l" :time="$day !== ''"/>@endforeach
        @endforeach
    </x-ui.table>
    @if (! $links instanceof \Illuminate\Support\Collection && $links->hasPages())<div class="mt-8"><x-ui.pager :of="$links"/></div>@endif
    @endif
</section>
@endif
