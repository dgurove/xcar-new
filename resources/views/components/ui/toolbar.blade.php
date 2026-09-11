{{-- Тулбар списка одной строкой: сортировка (select на десктопе, шторка на
     телефоне), полоса пилюль, слот extra (переключатель вида, «Новый»), фильтры
     в шторке с GET-формой. Всё состояние — в адресе.
     sorts: ключ → [подпись, есть ли направление] или ключ → подпись;
     pills: ключ → подпись; counts: ключ → число; hidden: поля, которые переживают фильтр. --}}
@props(['sorts' => [], 'sort' => '', 'sortParam' => 'sort', 'pills' => [], 'pill' => '', 'pillParam' => 'view', 'counts' => [], 'hidden' => [], 'name' => 'list', 'action' => null])
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
<div {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    @if ($norm->isNotEmpty())
        {{-- Десктоп: select + направление --}}
        <form method="get" action="{{ $action }}" class="hidden shrink-0 items-center gap-2 sm:flex" data-controller="autosubmit">
            @foreach ($query as $k => $v)@if ($k !== $sortParam && $k !== 'page' && !is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
            <select name="{{ $sortParam }}" class="field-input field-s !w-auto !bg-surface !pr-9" data-action="autosubmit#submit" aria-label="Сортировка">
                @foreach ($norm as $key => [$label, $dir])
                    <option value="{{ $key === $currentKey ? $sort : ($dir ? '-'.$key : $key) }}" @selected($key === $currentKey)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($hasDir)
                <a href="{{ $url([$sortParam => $desc ? $currentKey : '-'.$currentKey, 'page' => null]) }}" class="btn btn-s btn-quiet btn-round" aria-label="{{ $desc ? 'По возрастанию' : 'По убыванию' }}">
                    <x-ui.icon name="arrow-up" class="size-5 transition-transform {{ $desc ? 'rotate-180' : '' }}"/>
                </a>
            @endif
        </form>
        {{-- Телефон: круглая кнопка → шторка --}}
        <div class="contents sm:hidden" data-controller="sheet">
            <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="sheet#open" aria-label="Сортировка" aria-controls="sort-{{ $name }}"><x-ui.icon name="sort" class="size-5"/></button>
            <x-ui.sheet id="sort-{{ $name }}" title="Сортировка">
                <div class="space-y-2">
                    @foreach ($norm as $key => [$label, $dir])
                        <a href="{{ $sortUrl($key) }}" class="flex items-center justify-between rounded-full px-4 py-2.5 text-sm transition-colors {{ $key === $currentKey ? 'bg-accent text-white' : 'bg-surface-2 text-ink hover:bg-surface-3' }}">
                            {{ $label }}
                            @if ($key === $currentKey && $dir)<x-ui.icon name="arrow-up" class="size-4 {{ $desc ? 'rotate-180' : '' }}"/>@endif
                        </a>
                    @endforeach
                </div>
            </x-ui.sheet>
        </div>
    @endif

    @if ($pills)
        <div class="min-w-0 flex-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <div class="flex flex-nowrap items-center gap-2">
                @foreach ($pills as $key => $label)
                    <a href="{{ $url([$pillParam => $key === '' || $key === array_key_first($pills) ? null : $key, 'page' => null]) }}" class="pill" @if ((string) $pill === (string) $key) aria-current="true" @endif>
                        {{ $label }}@if (!empty($counts[$key])) <span class="nums opacity-70">{{ $counts[$key] }}</span>@endif
                    </a>
                @endforeach
            </div>
        </div>
    @else
        <div class="flex-1"></div>
    @endif

    {{ $extra ?? '' }}

    @isset($filters)
        <div class="contents" data-controller="sheet">
            <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="sheet#open" aria-label="Фильтры" aria-controls="filters-{{ $name }}"><x-ui.icon name="filter" class="size-5"/></button>
            <x-ui.sheet id="filters-{{ $name }}" title="Фильтры">
                <form method="get" action="{{ $action }}" class="space-y-3">
                    @if ($sort !== '' && $sort !== null)<input type="hidden" name="{{ $sortParam }}" value="{{ $sort }}">@endif
                    @if ($pill !== '' && $pill !== null)<input type="hidden" name="{{ $pillParam }}" value="{{ $pill }}">@endif
                    @foreach ($hidden as $k => $v)@if ($v !== null && $v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
                    {{ $filters }}
                    <div class="flex gap-2 pt-2">
                        <button type="submit" class="btn btn-s btn-accent flex-1">Показать</button>
                        <a href="{{ $action.(array_filter($hidden) ? '?'.http_build_query(array_filter($hidden)) : '') }}" class="btn btn-s btn-quiet">Сброс</a>
                    </div>
                </form>
            </x-ui.sheet>
        </div>
    @endisset
</div>
