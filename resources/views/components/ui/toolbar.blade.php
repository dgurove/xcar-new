{{-- Тулбар списка: полоса пилюль (листается вбок), за ней круглые кнопки — сортировка и вид (меню у кнопки), лупа; справа
     действия экрана. Ниже — ряд чипов фильтра (x-ui.facets: менеджер, вендор, этап…). На телефоне пилюли идут своей
     строкой сверху, во второй кнопки, в третьей чипы. Всё состояние — в адресе, кроме поиска: лупа ставит тулбару
     data-searching, ряд кнопок и чипов уходит, на месте — поле с «Отмена» (как в iOS); список сужается по ходу
     набора по всему списку, мимо пилюль и чипов (live_search_controller), адрес не меняется.
     sort — App\Support\Sort списка (поля и текущее значение), пусто — кнопки сортировки нет;
     pills: ключ → подпись; dots: ключ → тон точки перед подписью (fresh, hot — легенда полос каталога); counts: ключ → число или сумма строкой («360 500 ₽» в «Деньгах»); tones: ключ → класс пилюли (pill-danger у «Просрочено»); badges: ключ → ключ числа в counts, лаймовый бейдж после числа (у «Без продажной цены» — оценки менеджеров ждут решения);
     pillDefault=false — первая пилюля («Все») не выделяется: зелёное только у сужающего фильтра;
     pillHome — пилюля экрана по умолчанию, её ключа нет в адресе (обычно первая; у почты первая «Требуют внимания», а открывается «Все»);
     facets — App\Support\Facets списка (чипы); search — подсказка в поле поиска (пусто — лупы нет),
     searchTarget — что подменять ответом (#list), searchUrl — куда спрашивать, если не сюда (чаты: открытый чат
     читать нельзя);
     слот pillsExtra — пилюли-ссылки в хвосте ряда («+ Группа», «Ссылки 2»);
     слот extra — кнопки экрана в левой группе после сортировки (переключатель вида),
     слот actions — у правого края («+», «Из писем»).
     Сортировка — окошко x-ui.sort: поле и направление (владелец 06.10.2026: «кнопка сортировки, окошко — выбор
     критерия и ascending/descending»); прежнего меню исходов словами («Сначала дешёвые») больше нет. --}}
@props(['sort' => null, 'sortParam' => 'sort', 'pills' => [], 'dots' => [], 'pill' => '', 'pillParam' => 'view', 'pillDefault' => true, 'pillHome' => null, 'counts' => [], 'badges' => [], 'tones' => [], 'name' => 'list', 'action' => null, 'search' => null, 'searchTarget' => '#list', 'searchUrl' => null, 'q' => null, 'facets' => null, 'searchOpen' => false, 'sortToggles' => []])
@php
    $action ??= '/'.ltrim(request()->path(), '/');
    $query = request()->query();
    $url = fn (array $set) => $action.'?'.str_replace('%2C', ',', http_build_query(array_filter(array_merge($query, $set), fn ($v) => $v !== null && $v !== '')));
    $sortUrl = fn (string $value) => \App\Support\Sort::url($value, $sortParam, $action);
    $q ??= is_string(request('q')) ? request('q') : '';
    // searchOpen — поле открыто всегда, без лупы и «Отмена» (колонка чатов, 06.10.2026: «нет смысла сворачивать»).
    $searching = $search && ($searchOpen || trim($q) !== '');
    // Короткий ряд пилюль — на широком экране чипы встают в его строку (`.toolbar--fit`, app.css).
    $fit = $pills && mb_strlen(implode('', $pills)) <= 40;
@endphp
<div {{ $attributes->merge(['class' => 'toolbar flex items-center gap-2 max-md:gap-y-3'.($fit ? ' toolbar--fit' : '')]) }} @if ($search) data-controller="live-search" data-live-search-target-value="{{ $searchTarget }}" @if ($searchUrl) data-live-search-url-value="{{ $searchUrl }}" @endif @if ($searchOpen) data-live-search-open-value="true" @endif @endif @if ($searching) data-searching @endif>
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
        <div class="toolbar-pills min-w-0 flex-1 snap-x overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden max-md:order-first max-md:-mx-4 max-md:flex-initial max-md:px-4" data-title-anchor>
            <div class="flex flex-nowrap items-center gap-2">
                @foreach ($pills as $key => $label)
                    <a href="{{ $url([$pillParam => $key === '' || $key === ($pillHome ?? array_key_first($pills)) ? null : $key, 'page' => null]) }}" class="pill {{ (string) $pill === (string) $key ? '' : ($tones[$key] ?? '') }}" @isset($dots[$key]) data-tone="{{ $dots[$key] }}" @endisset data-turbo-action="replace" @if ((string) $pill === (string) $key && ($pillDefault || $key !== array_key_first($pills))) aria-current="true" @endif>
                        @isset($dots[$key])@if ($dots[$key] === 'urgent')<x-ui.icon name="fire" fill class="mr-1 size-4 {{ (string) $pill === (string) $key ? '' : 'text-fire' }}"/>@else<span class="card-tone card-tone--{{ $dots[$key] }}"></span>@endif @endisset{{ $label }}@if (array_key_exists($key, $counts)) <span class="nums opacity-70" data-pill-count="{{ $key }}">{{ $counts[$key] ?: '' }}</span>@endif
                        @isset($badges[$key])<span class="badge nums" data-pill-count="{{ $badges[$key] }}">{{ ($counts[$badges[$key]] ?? 0) ?: '' }}</span>@endisset
                    </a>
                @endforeach
                {{ $pillsExtra ?? '' }}
            </div>
        </div>
    @endif
    {{-- Без пилюль кнопки слева, действия справа (`ml-auto`), на ПК чипы встают в ту же строку после кнопок. Распорки
         нет: её `md:block` из слоя утилит перебивал правило «спрятать без пилюль», и кнопки с чипами уезжали на середину. --}}

    {{-- Кнопки настройки списка — группой: на телефоне переносятся целиком, а не по одной (сортировка у пилюль, вид и
         лупа рядом ниже); на ПК обёртки нет (`contents`). --}}
    @if ($sort || isset($extra) || ($search && ! $searchOpen))
    <div class="toolbar-tools">
        @if ($sort)
            <x-ui.sort :sort="$sort" :url="$sortUrl" :name="$name" :toggles="$sortToggles"/>
        @endif

        {{-- Кнопки экрана (переключатель вида) — после сортировки: сначала то, чем список настраивают; лупа — за ними. --}}
        {{ $extra ?? '' }}

        @if ($search && ! $searchOpen)
            <button type="button" class="btn btn-s btn-quiet btn-round shrink-0 toolbar-lens" data-action="live-search#open" aria-label="Поиск"><x-ui.icon name="search" class="size-5"/></button>
        @endif
    </div>
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
