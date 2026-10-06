{{-- Тулбар списка: полоса пилюль (листается вбок), за ней круглые кнопки — сортировка и вид (меню у кнопки), лупа; справа
     действия экрана. Ниже — ряд чипов фильтра (x-ui.facets: менеджер, вендор, этап…). На телефоне пилюли идут своей
     строкой сверху, во второй кнопки, в третьей чипы. Всё состояние — в адресе, кроме поиска: лупа ставит тулбару
     data-searching, ряд кнопок и чипов уходит, на месте — поле с «Отмена» (как в iOS); список сужается по ходу
     набора по всему списку, мимо пилюль и чипов (live_search_controller), адрес не меняется.
     sorts: ключ → [подпись, есть ли направление] или ключ → подпись;
     pills: ключ → подпись; counts: ключ → число или сумма строкой («360 500 ₽» в «Деньгах»); tones: ключ → класс пилюли (pill-danger у «Просрочено»);
     pillDefault=false — первая пилюля («Все») не выделяется: зелёное только у сужающего фильтра;
     pillHome — пилюля экрана по умолчанию, её ключа нет в адресе (обычно первая; у почты первая «Требуют внимания», а открывается «Все»);
     facets — App\Support\Facets списка (чипы); search — подсказка в поле поиска (пусто — лупы нет),
     searchTarget — что подменять ответом (#list), searchUrl — куда спрашивать, если не сюда (чаты: открытый чат
     читать нельзя);
     слот pillsExtra — пилюли-ссылки в хвосте ряда («+ Группа», «Ссылки 2»);
     слот extra — кнопки экрана в левой группе после сортировки (переключатель вида),
     слот actions — у правого края («+», «Из писем»).
     Сортировка — только исходы словами, по строке на исход: «Сначала дешёвые», «Дольше ждут».
     Направления как отдельной кнопки нет — у каталога это два ключа («price» и «-price»): стрелку
     вверх-вниз никто не находил, а «по возрастанию» ни о чём не говорит. --}}
@props(['sorts' => [], 'sort' => '', 'sortParam' => 'sort', 'pills' => [], 'pill' => '', 'pillParam' => 'view', 'pillDefault' => true, 'pillHome' => null, 'counts' => [], 'tones' => [], 'name' => 'list', 'action' => null, 'search' => null, 'searchTarget' => '#list', 'searchUrl' => null, 'q' => null, 'facets' => null, 'searchOpen' => false])
@php
    $action ??= '/'.ltrim(request()->path(), '/');
    $query = request()->query();
    $url = fn (array $set) => $action.'?'.str_replace('%2C', ',', http_build_query(array_filter(array_merge($query, $set), fn ($v) => $v !== null && $v !== '')));
    $norm = collect($sorts)->map(fn ($v) => is_array($v) ? $v[0] : $v);
    $current = (string) $sort;
    $sortUrl = fn (string $key) => $url([$sortParam => $key, 'page' => null]);
    $q ??= is_string(request('q')) ? request('q') : '';
    // searchOpen — поле открыто всегда, без лупы и «Отмена» (колонка чатов, 06.10.2026: «нет смысла сворачивать»).
    $searching = $search && ($searchOpen || trim($q) !== '');
@endphp
<div {{ $attributes->merge(['class' => 'toolbar flex items-center gap-2 max-md:gap-y-3']) }} @if ($search) data-controller="live-search" data-live-search-target-value="{{ $searchTarget }}" @if ($searchUrl) data-live-search-url-value="{{ $searchUrl }}" @endif @if ($searchOpen) data-live-search-open-value="true" @endif @endif @if ($searching) data-searching @endif>
    @if ($search)
        <form class="toolbar-search" role="search" data-action="submit->live-search#stop">
            <x-ui.icon name="search" class="size-4 shrink-0 text-ink-muted"/>
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ $search }}" autocomplete="off" enterkeyhint="search" aria-label="{{ $search }}"
                   data-live-search-target="input" data-action="input->live-search#input keydown->live-search#key">
            <button type="button" class="toolbar-search-x" data-live-search-target="clear" data-action="live-search#clear" aria-label="Очистить" hidden><x-ui.icon name="x" class="size-4"/></button>
        </form>
        @unless ($searchOpen)<button type="button" class="toolbar-cancel" data-action="live-search#cancel">Отмена</button>@endunless
    @endif

    @if ($pills)
        <div class="toolbar-pills min-w-0 flex-1 snap-x overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden max-md:order-first max-md:-mx-4 max-md:basis-full max-md:px-4" data-title-anchor>
            <div class="flex flex-nowrap items-center gap-2">
                @foreach ($pills as $key => $label)
                    <a href="{{ $url([$pillParam => $key === '' || $key === ($pillHome ?? array_key_first($pills)) ? null : $key, 'page' => null]) }}" class="pill {{ (string) $pill === (string) $key ? '' : ($tones[$key] ?? '') }}" data-turbo-action="replace" @if ((string) $pill === (string) $key && ($pillDefault || $key !== array_key_first($pills))) aria-current="true" @endif>
                        {{ $label }}@if (array_key_exists($key, $counts)) <span class="nums opacity-70" data-pill-count="{{ $key }}">{{ $counts[$key] ?: '' }}</span>@endif
                    </a>
                @endforeach
                {{ $pillsExtra ?? '' }}
            </div>
        </div>
    @endif
    {{-- Без пилюль кнопки слева, действия справа (`ml-auto`), на ПК чипы встают в ту же строку после кнопок. Распорки
         нет: её `md:block` из слоя утилит перебивал правило «спрятать без пилюль», и кнопки с чипами уезжали на середину. --}}

    @if ($norm->isNotEmpty())
        {{-- Сортировка — круглая кнопка с меню у неё, как вид списка: строки через линию, у текущей лаймовая галка
             справа (.menu-item[aria-current]), одинаково на телефоне и на компьютере. --}}
        <div class="contents" data-controller="menu">
            <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="menu#toggle" aria-label="Сортировка: {{ $norm[$current] ?? 'по умолчанию' }}" aria-haspopup="menu" aria-controls="sort-{{ $name }}"><x-ui.icon name="sort" class="size-5"/></button>
            <div id="sort-{{ $name }}" class="menu" popover data-menu-target="list" role="menu" aria-label="Сортировка">
                @foreach ($norm as $key => $label)
                    <a href="{{ $sortUrl($key) }}" class="menu-item" role="menuitem" data-turbo-action="replace" data-action="menu#close" @if ((string) $key === $current) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Кнопки экрана (переключатель вида) — после сортировки: сначала то, чем список настраивают; лупа — за ними. --}}
    {{ $extra ?? '' }}

    @if ($search && ! $searchOpen)
        <button type="button" class="btn btn-s btn-quiet btn-round shrink-0 toolbar-lens" data-action="live-search#open" aria-label="Поиск"><x-ui.icon name="search" class="size-5"/></button>
    @endif

    @isset($actions)
        {{-- Действия экрана («+», «Из писем») — у правого края: добавление ищут справа, а вид, сортировка
             и фильтры остаются слева. Отступ, а не распорка: лишний gap ронял последнюю кнопку на строку ниже. --}}
        <div class="toolbar-actions ml-auto flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endisset

    {{-- Пока идёт живой поиск, сервер отдаёт страницу ради списка — чипы ему не нужны. --}}
    @if ($facets && ! request()->headers->has('X-List'))
        <x-ui.facets :facets="$facets" :name="$name"/>
    @endif
</div>
