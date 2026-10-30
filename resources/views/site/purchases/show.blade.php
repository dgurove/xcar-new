@php
    $qs = http_build_query(array_filter($filters + [\App\Support\ListView::PARAM => request(\App\Support\ListView::PARAM)], fn ($v) => $v !== null && $v !== ''));
    $view = \App\Support\ListView::pick(request(), $cars->total());
@endphp
<x-ui.shell :title="$purchase->publicTitle($group)" :back="['Закупки', '/purchases']" :trail="[['Главная', '/'], ['Закупки', '/purchases'], [$purchase->publicTitle($group)]]" :detail="$detail">
    {{-- Под заголовком — срок и сколько названо строкой, виды техники пилюлями; без коробки-героя и полосы прогресса. --}}
    <div class="-mt-2 flex flex-wrap items-center gap-x-3 gap-y-2">
        <x-purchase.deadline :purchase="$purchase" class="text-sm"/>
        @if ($total > 0)<span class="nums text-sm text-ink-muted">{{ $done >= $total ? 'названы все цены' : 'названо '.$done.' из '.$total.' '.\App\Support\Plural::of($total, ['цены', 'цен', 'цен']) }}</span>@endif
    </div>
    @if (count($kinds) > 1)
        <div class="pills mt-3">
            <x-ui.pill :href="request()->fullUrlWithQuery(['kind' => null, 'page' => null])" :current="empty($filters['kind'])">Вся техника</x-ui.pill>
            @foreach ($kinds as $kind)<x-ui.pill :href="request()->fullUrlWithQuery(['kind' => $kind->value, 'page' => null])" :current="($filters['kind'] ?? '') === $kind->value">{{ $kind->label() }}</x-ui.pill>@endforeach
        </div>
    @endif

    <x-ui.toolbar class="mt-5" :sort="$sort" :pills="\App\Http\Site\PurchaseController::PRESETS" :pill="$filters['preset'] ?? 'all'" pill-param="preset" name="purchase" search="Номер, VIN, марка">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
    </x-ui.toolbar>

    <div class="mt-6" id="list">
        @if ($cars->isEmpty())
            <x-ui.empty :href="'/purchases/'.$purchase->number.($group ? '?group='.$group->value : '')" link="Сбросить фильтры">По этим условиям ничего не нашлось</x-ui.empty>
        @else
            @if (\App\Support\ListView::isTable($view))
            @php $columns = \App\Http\Site\PurchaseController::table(count($kinds) > 1); @endphp
            <x-ui.table :table="$columns" id="cars" :view="$view">
                <x-slot:head><x-table.head :table="$columns" :sort="$sort"/></x-slot:head>
                @foreach ($cars as $car)<x-purchase.site-table-row :car="$car" :purchase="$purchase" :query="$qs" :table="$columns"/>@endforeach
            </x-ui.table>
            @else
            <div class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
                @foreach ($cars as $car)<x-purchase.card :car="$car" :purchase="$purchase" :query="$qs" :show-kind="count($kinds) > 1"/>@endforeach
            </div>
            @endif
            <div class="mt-10"><x-ui.pager :of="$cars" :sizes="\App\Support\ListView::perSizes($view)"/></div>
        @endif
    </div>
</x-ui.shell>
