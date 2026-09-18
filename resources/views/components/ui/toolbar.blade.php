{{-- Тулбар списка одной строкой: сортировка (select на десктопе, шторка на
     телефоне), полоса пилюль, слот extra (переключатель вида, «Новый»), фильтры
     в шторке с GET-формой. Всё состояние — в адресе. sortSide=right — сортировка
     справа, у фильтров: слева остаются одни пилюли.
     sorts: ключ → [подпись, есть ли направление] или ключ → подпись;
     pills: ключ → подпись; counts: ключ → число; hidden: поля, которые переживают фильтр;
     pillDefault=false — первая пилюля («Все») не выделяется: зелёное только у сужающего фильтра;
     слот pillsExtra — пилюли-ссылки в хвосте ряда («+ Группа», «Ссылки 2»). --}}
@props(['sorts' => [], 'sort' => '', 'sortParam' => 'sort', 'sortSide' => 'left', 'pills' => [], 'pill' => '', 'pillParam' => 'view', 'pillDefault' => true, 'counts' => [], 'hidden' => [], 'name' => 'list', 'action' => null])
@php
    $action ??= '/'.ltrim(request()->path(), '/');
    $query = request()->query();
    $url = fn (array $set) => $action.'?'.http_build_query(array_filter(array_merge($query, $set), fn ($v) => $v !== null && $v !== ''));
    $norm = collect($sorts)->map(fn ($v) => is_array($v) ? $v : [$v, false]);
    $currentKey = ltrim((string) $sort, '-');
    $desc = str_starts_with((string) $sort, '-');
    $hasDir = (bool) ($norm[$currentKey][1] ?? false);
    // Ссылка на критерий: активный с направлением — переворот, чужой — своё направление по умолчанию (убывание).
    $sortUrl = fn (string $key) => $url([$sortParam => $key === $currentKey ? ($norm[$key][1] ? ($desc ? $key : '-'.$key) : $key) : ($norm[$key][1] ? '-'.$key : $key), 'page' => null]);
@endphp
<div {{ $attributes->merge(['class' => 'toolbar flex items-center gap-3'.($sortSide === 'right' ? ' toolbar--sort-right' : '')]) }}>
    @if ($sortSide === 'left')
    @if ($norm->isNotEmpty())
        {{-- Десктоп: select + направление --}}
        <form method="get" action="{{ $action }}" class="hidden shrink-0 items-center gap-2 sm:flex" data-controller="autosubmit" data-turbo-action="replace">
            @foreach ($query as $k => $v)@if ($k !== $sortParam && $k !== 'page' && !is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
            <select name="{{ $sortParam }}" class="field-input field-s !w-auto !bg-surface !pr-9" data-action="autosubmit#submit" aria-label="Сортировка">
                @foreach ($norm as $key => [$label, $dir])
                    <option value="{{ $key === $currentKey ? $sort : ($dir ? '-'.$key : $key) }}" @selected($key === $currentKey)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($hasDir)
                <a href="{{ $url([$sortParam => $desc ? $currentKey : '-'.$currentKey, 'page' => null]) }}" class="btn btn-s btn-quiet btn-round" data-turbo-action="replace" aria-label="{{ $desc ? 'По возрастанию' : 'По убыванию' }}">
                    <x-ui.icon name="arrow-up" class="size-5 transition-transform {{ $desc ? 'rotate-180' : '' }}"/>
                </a>
            @endif
        </form>
        {{-- Телефон: кнопка с именем сортировки → шторка --}}
        <div class="contents sm:hidden" data-controller="sheet">
            <button type="button" class="toolbar-sort btn btn-s btn-quiet gap-1.5 rounded-full" data-action="sheet#open" aria-label="Сортировка" aria-controls="sort-{{ $name }}"><x-ui.icon name="sort" class="size-5 shrink-0"/><span class="truncate">{{ $norm[$currentKey][0] ?? 'Сортировка' }}</span>@if ($hasDir)<x-ui.icon name="arrow-up" class="size-4 shrink-0 {{ $desc ? 'rotate-180' : '' }}"/>@endif</button>
            <x-ui.sheet id="sort-{{ $name }}" title="Сортировка">
                <div class="space-y-2">
                    @foreach ($norm as $key => [$label, $dir])
                        <a href="{{ $sortUrl($key) }}" data-turbo-action="replace" class="flex items-center justify-between rounded-full px-4 py-2.5 text-sm transition-colors active:bg-surface-3 {{ $key === $currentKey ? 'bg-accent text-white' : 'bg-surface-2 text-ink hover:bg-surface-3' }}">
                            {{ $label }}
                            @if ($key === $currentKey && $dir)<x-ui.icon name="arrow-up" class="size-4 {{ $desc ? 'rotate-180' : '' }}"/>@endif
                        </a>
                    @endforeach
                </div>
            </x-ui.sheet>
        </div>
    @endif
    @endif

    @if ($pills)
        <div class="toolbar-pills min-w-0 flex-1 snap-x overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden max-md:order-first max-md:-mx-4 max-md:basis-full max-md:px-4" data-title-anchor>
            <div class="flex flex-nowrap items-center gap-2">
                @foreach ($pills as $key => $label)
                    <a href="{{ $url([$pillParam => $key === '' || $key === array_key_first($pills) ? null : $key, 'page' => null]) }}" class="pill" data-turbo-action="replace" @if ((string) $pill === (string) $key && ($pillDefault || $key !== array_key_first($pills))) aria-current="true" @endif>
                        {{ $label }}@if (!empty($counts[$key])) <span class="nums opacity-70">{{ $counts[$key] }}</span>@endif
                    </a>
                @endforeach
                {{ $pillsExtra ?? '' }}
            </div>
        </div>
    @else
        <div class="flex-1"></div>
    @endif

    {{ $extra ?? '' }}

    @if ($sortSide === 'right')
    @if ($norm->isNotEmpty())
        {{-- Десктоп: select + направление --}}
        <form method="get" action="{{ $action }}" class="hidden shrink-0 items-center gap-2 sm:flex" data-controller="autosubmit" data-turbo-action="replace">
            @foreach ($query as $k => $v)@if ($k !== $sortParam && $k !== 'page' && !is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
            <select name="{{ $sortParam }}" class="field-input field-s !w-auto !bg-surface !pr-9" data-action="autosubmit#submit" aria-label="Сортировка">
                @foreach ($norm as $key => [$label, $dir])
                    <option value="{{ $key === $currentKey ? $sort : ($dir ? '-'.$key : $key) }}" @selected($key === $currentKey)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($hasDir)
                <a href="{{ $url([$sortParam => $desc ? $currentKey : '-'.$currentKey, 'page' => null]) }}" class="btn btn-s btn-quiet btn-round" data-turbo-action="replace" aria-label="{{ $desc ? 'По возрастанию' : 'По убыванию' }}">
                    <x-ui.icon name="arrow-up" class="size-5 transition-transform {{ $desc ? 'rotate-180' : '' }}"/>
                </a>
            @endif
        </form>
        {{-- Телефон: кнопка с именем сортировки → шторка --}}
        <div class="contents sm:hidden" data-controller="sheet">
            <button type="button" class="toolbar-sort btn btn-s btn-quiet gap-1.5 rounded-full" data-action="sheet#open" aria-label="Сортировка" aria-controls="sort-{{ $name }}"><x-ui.icon name="sort" class="size-5 shrink-0"/><span class="truncate">{{ $norm[$currentKey][0] ?? 'Сортировка' }}</span>@if ($hasDir)<x-ui.icon name="arrow-up" class="size-4 shrink-0 {{ $desc ? 'rotate-180' : '' }}"/>@endif</button>
            <x-ui.sheet id="sort-{{ $name }}" title="Сортировка">
                <div class="space-y-2">
                    @foreach ($norm as $key => [$label, $dir])
                        <a href="{{ $sortUrl($key) }}" data-turbo-action="replace" class="flex items-center justify-between rounded-full px-4 py-2.5 text-sm transition-colors active:bg-surface-3 {{ $key === $currentKey ? 'bg-accent text-white' : 'bg-surface-2 text-ink hover:bg-surface-3' }}">
                            {{ $label }}
                            @if ($key === $currentKey && $dir)<x-ui.icon name="arrow-up" class="size-4 {{ $desc ? 'rotate-180' : '' }}"/>@endif
                        </a>
                    @endforeach
                </div>
            </x-ui.sheet>
        </div>
    @endif
    @endif

    @isset($filters)
        <div class="contents" data-controller="sheet">
            <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="sheet#open" aria-label="Фильтры" aria-controls="filters-{{ $name }}"><x-ui.icon name="filter" class="size-5"/></button>
            <x-ui.sheet id="filters-{{ $name }}" title="Фильтры">
                <form method="get" action="{{ $action }}" class="space-y-3" data-turbo-action="replace">
                    @if ($sort !== '' && $sort !== null)<input type="hidden" name="{{ $sortParam }}" value="{{ $sort }}">@endif
                    @if ($pill !== '' && $pill !== null)<input type="hidden" name="{{ $pillParam }}" value="{{ $pill }}">@endif
                    @foreach ($hidden as $k => $v)@if ($v !== null && $v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
                    {{ $filters }}
                    <div class="flex gap-2 pt-2">
                        <button type="submit" class="btn btn-s btn-accent flex-1">Показать</button>
                        <a href="{{ $action.(array_filter($hidden) ? '?'.http_build_query(array_filter($hidden)) : '') }}" class="btn btn-s btn-quiet" data-turbo-action="replace">Сброс</a>
                    </div>
                </form>
            </x-ui.sheet>
        </div>
    @endisset
</div>
