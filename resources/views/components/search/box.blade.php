{{-- Поиск в шапке — один на три хоста (`Search\Search::for`, global_search_controller): на ПК поле с выпадающей выдачей,
     на телефоне (`phone`) — квадрат-лупа и шторка: поле как лупа списков (`x-search.field`) и та же выдача. Подсказка — что здесь ищут. --}}
@props(['phone' => false])
@php
    $hint = \App\Search\Search::hint();
    $frame = $phone ? 'global-search-phone' : 'global-search';
@endphp
@if ($phone)
    <div class="contents" data-controller="sheet">
        <button type="button" class="header-btn header-sq" data-action="sheet#open" aria-label="Поиск"><x-ui.icon name="search" class="size-[18px]"/></button>
        <x-ui.sheet id="search-sheet" title="Поиск" class="sheet-steady-phone">
            <div data-controller="global-search" data-action="click->global-search#picked">
                <x-search.field :hint="$hint" autofocus/>
                <div class="pills mt-3" data-global-search-target="recent" hidden></div>
                <turbo-frame id="{{ $frame }}" target="_top" class="mt-3 block" data-global-search-target="frame"></turbo-frame>
            </div>
        </x-ui.sheet>
    </div>
@else
    <div class="relative" data-controller="global-search" data-action="click->global-search#picked">
        <form method="get" action="/search" class="header-btn header-h header-search relative justify-start px-3" role="search">
            <x-ui.icon name="search" class="size-4 shrink-0 text-ink-muted"/>
            <input type="search" name="q" placeholder="{{ $hint }}" class="w-28 sm:w-36 lg:w-52" autocomplete="off" aria-label="Поиск" aria-keyshortcuts="/ Meta+K"
                   data-global-search-target="input" data-action="input->global-search#input focus->global-search#focus keydown->global-search#keydown">
        </form>
        <div class="search-pop" hidden data-global-search-target="pop">
            <div class="pills" data-global-search-target="recent" hidden></div>
            <turbo-frame id="{{ $frame }}" target="_top" data-global-search-target="frame"></turbo-frame>
        </div>
    </div>
@endif
