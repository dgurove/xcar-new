@php
    $qs = http_build_query(array_filter($filters + [\App\Support\ListView::PARAM => request(\App\Support\ListView::PARAM)], fn ($v) => $v !== null && $v !== ''));
    $view = \App\Support\ListView::fromRequest(request());
@endphp
<x-ui.shell :title="$purchase->publicTitle($group)" :heading="false" :back="['Закупки', '/purchases']" :trail="[['Главная', '/'], ['Закупки', '/purchases'], [$purchase->publicTitle($group)]]">
    <div class="box">
        <div class="has-back flex items-center gap-3">
            <x-ui.back :back="['Закупки', '/purchases']"/>
            <h1 class="min-w-0 text-[26px] sm:text-[32px]">{{ $purchase->publicTitle($group) }}</h1>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <x-purchase.deadline :purchase="$purchase"/>
            @if (count($kinds) > 1)
                <x-ui.pill tone="plain" :href="request()->fullUrlWithQuery(['kind' => null, 'page' => null])" :current="empty($filters['kind'])">Вся техника</x-ui.pill>
                @foreach ($kinds as $kind)<x-ui.pill tone="plain" :href="request()->fullUrlWithQuery(['kind' => $kind->value, 'page' => null])" :current="($filters['kind'] ?? '') === $kind->value">{{ $kind->label() }}</x-ui.pill>@endforeach
            @endif
        </div>
        @if ($total > 0)
            <div class="mt-5">
                @if ($done >= $total)
                    <p>Названы все цены</p>
                @else
                    <p class="nums font-normal">Названо {{ $done }} из {{ $total }} {{ \App\Support\Plural::of($total, ['цены', 'цен', 'цен']) }}</p>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full rounded-full bg-accent" style="width: {{ (int) round($done / $total * 100) }}%"></div></div>
                @endif
            </div>
        @endif
    </div>

    <x-ui.toolbar class="mt-5" :sorts="\App\Http\Site\PurchaseController::SORTS" :sort="$filters['sort'] ?? 'dl'" :pills="\App\Http\Site\PurchaseController::PRESETS" :pill="$filters['preset'] ?? 'all'" pill-param="preset" :hidden="['group' => $group?->value, 'kind' => $filters['kind'] ?? null, \App\Support\ListView::PARAM => $view]" name="purchase">
        <x-slot:extra><x-ui.view-switch/></x-slot:extra>
        <x-slot:filters>
            <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ДЛ, VIN, марка" class="field-input field-s">
        </x-slot:filters>
    </x-ui.toolbar>

    <div class="mt-6">
        @if ($cars->isEmpty())
            <x-ui.empty :href="'/purchases/'.$purchase->number.($group ? '?group='.$group->value : '')" link="Сбросить фильтры">По этим условиям ничего не нашлось</x-ui.empty>
        @else
            @if ($view === \App\Support\ListView::TABLE)
            <x-ui.table id="cars">
                <x-slot:head>
                    <tr>
                        <th>Машина</th>
                        @if (count($kinds) > 1)<th class="hidden w-32 sm:table-cell">Тип</th>@endif
                        <th class="hidden w-36 sm:table-cell">Город</th>
                        <th class="hidden w-28 sm:table-cell">ДЛ</th>
                        <th class="num w-28 sm:w-56">Цена</th>
                    </tr>
                </x-slot:head>
                @foreach ($cars as $car)<x-purchase.site-table-row :car="$car" :purchase="$purchase" :query="$qs" :show-kind="count($kinds) > 1"/>@endforeach
            </x-ui.table>
            @else
            <div class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
                @foreach ($cars as $car)<x-purchase.card :car="$car" :purchase="$purchase" :query="$qs" :show-kind="count($kinds) > 1"/>@endforeach
            </div>
            @endif
            <div class="mt-10">{{ $cars->links() }}</div>
        @endif
    </div>
</x-ui.shell>
