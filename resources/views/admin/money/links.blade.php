{{-- «По ссылкам» строками (меню вида; по умолчанию — таблица `admin/money/links-table`): строка банка `x-billing.link-row`
     — кто платит и сумма, под ними ТС и статус. По дате — группами по дням. Отменённые — чипом, бледными. --}}
@php
    use App\Http\Admin\MoneyLinks as L;
    $items = $links instanceof \Illuminate\Support\Collection ? $links : collect($links->items());
    $days = ! $titled && ($sort?->key ?? 'created') === 'created' ? $items->groupBy(fn ($l) => L::day($l->created_at)) : collect(['' => $items]);
@endphp
@if (! ($titled && $items->isEmpty()))
<section class="mt-4" data-search-group>
    @if ($titled)<h2 class="list-head">{{ \App\Http\Admin\MoneyController::TABS['links'] }}</h2>@endif
    @foreach ($days as $day => $group)
        @if ($day !== '')<div @class(['list-head', 'mt-2' => ! $loop->first])>{{ $day }}</div>@endif
        <div class="list">
            @foreach ($group as $l)<x-billing.link-row :link="$l" detail/>@endforeach
        </div>
    @endforeach
    @if (! $links instanceof \Illuminate\Support\Collection && $links->hasPages())<div class="mt-8"><x-ui.pager :of="$links"/></div>@endif
</section>
@endif
