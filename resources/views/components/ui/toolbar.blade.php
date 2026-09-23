{{-- Тулбар списка одной строкой: слева поиск (у кого он есть), дальше полоса пилюль (листается вбок),
     справа две круглые кнопки — сортировка и фильтры, обе шторками. На телефоне пилюли идут своей
     строкой сверху, во второй остаются поиск и кнопки. Всё состояние — в адресе, кроме поиска:
     поиск не фильтр, адрес он не меняет (live_search_controller подменяет список на месте).
     sorts: ключ → [подпись, есть ли направление] или ключ → подпись;
     pills: ключ → подпись; counts: ключ → число; tones: ключ → класс пилюли (pill-danger у «Просрочено»); hidden: поля, которые переживают фильтр;
     pillDefault=false — первая пилюля («Все») не выделяется: зелёное только у сужающего фильтра;
     search — подсказка в поле поиска (пусто — поля нет), searchTarget — что подменять ответом;
     слот pillsExtra — пилюли-ссылки в хвосте ряда («+ Группа», «Ссылки 2»);
     слот extra — кнопки экрана слева (переключатель вида), слот actions — у правого края («+», «Из писем»).
     Сортировка — только исходы словами, по строке на исход: «Сначала дешёвые», «Дольше ждут».
     Направления как отдельной кнопки нет — у каталога это два ключа («price» и «-price»): стрелку
     вверх-вниз никто не находил, а «по возрастанию» ни о чём не говорит. --}}
@props(['sorts' => [], 'sort' => '', 'sortParam' => 'sort', 'pills' => [], 'pill' => '', 'pillParam' => 'view', 'pillDefault' => true, 'counts' => [], 'tones' => [], 'hidden' => [], 'name' => 'list', 'action' => null, 'search' => null, 'searchTarget' => null, 'q' => ''])
@php
    $action ??= '/'.ltrim(request()->path(), '/');
    $query = request()->query();
    $url = fn (array $set) => $action.'?'.http_build_query(array_filter(array_merge($query, $set), fn ($v) => $v !== null && $v !== ''));
    $norm = collect($sorts)->map(fn ($v) => is_array($v) ? $v[0] : $v);
    $current = (string) $sort;
    $sortUrl = fn (string $key) => $url([$sortParam => $key, 'page' => null]);
@endphp
<div {{ $attributes->merge(['class' => 'toolbar flex items-center gap-2 max-md:gap-y-3']) }}>
    @if ($search)
        <form class="toolbar-search" role="search" data-controller="live-search" data-live-search-target-value="{{ $searchTarget ?? '#threads' }}" data-action="submit->live-search#stop">
            <x-ui.icon name="search" class="size-4 shrink-0 text-ink-muted"/>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ $search }}" autocomplete="off" enterkeyhint="search" aria-label="{{ $search }}"
                   data-live-search-target="input" data-action="input->live-search#input keydown.esc->live-search#clear">
            <button type="button" class="toolbar-search-x" data-live-search-target="clear" data-action="live-search#clear" aria-label="Очистить" hidden><x-ui.icon name="x" class="size-4"/></button>
        </form>
    @endif

    @if ($pills)
        <div class="toolbar-pills min-w-0 flex-1 snap-x overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden max-md:order-first max-md:-mx-4 max-md:basis-full max-md:px-4" data-title-anchor>
            <div class="flex flex-nowrap items-center gap-2">
                @foreach ($pills as $key => $label)
                    <a href="{{ $url([$pillParam => $key === '' || $key === array_key_first($pills) ? null : $key, 'page' => null]) }}" class="pill {{ (string) $pill === (string) $key ? '' : ($tones[$key] ?? '') }}" data-turbo-action="replace" @if ((string) $pill === (string) $key && ($pillDefault || $key !== array_key_first($pills))) aria-current="true" @endif>
                        {{ $label }}@if (!empty($counts[$key])) <span class="nums opacity-70">{{ $counts[$key] }}</span>@endif
                    </a>
                @endforeach
                {{ $pillsExtra ?? '' }}
            </div>
        </div>
    @else
        {{-- Без пилюль поиск уже растянут: на телефоне пустая распорка отняла бы у него половину строки. --}}
        <div class="hidden flex-1 md:block"></div>
    @endif

    {{ $extra ?? '' }}

    @if ($norm->isNotEmpty())
        {{-- Сортировка — круглая кнопка со шторкой, одинаково на телефоне и на компьютере. --}}
        <div class="contents" data-controller="sheet">
            <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="sheet#open" aria-label="Сортировка: {{ $norm[$current] ?? 'по умолчанию' }}" aria-controls="sort-{{ $name }}"><x-ui.icon name="sort" class="size-5"/></button>
            <x-ui.sheet id="sort-{{ $name }}" title="Сортировка">
                <div class="space-y-2">
                    @foreach ($norm as $key => $label)
                        <a href="{{ $sortUrl($key) }}" data-turbo-action="replace" class="flex items-center justify-between rounded-full px-4 py-2.5 text-sm transition-colors active:bg-surface-3 {{ (string) $key === $current ? 'bg-accent text-white' : 'bg-surface-2 text-ink hover:bg-surface-3' }}">
                            {{ $label }}
                            @if ((string) $key === $current)<x-ui.icon name="check" class="size-4"/>@endif
                        </a>
                    @endforeach
                </div>
            </x-ui.sheet>
        </div>
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

    @isset($actions)
        {{-- Действия экрана («+», «Из писем») — у правого края: добавление ищут справа, а вид, сортировка
             и фильтры остаются слева. Отступ, а не распорка: лишний gap ронял последнюю кнопку на строку ниже. --}}
        <div class="ml-auto flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
