{{-- Поле поиска в шторке телефона и на странице `/search` — как лупа списков (`.toolbar-search`): лупа, поле, крестик с
     первым знаком. Внутри `data-controller="global-search"`. --}}
@props(['hint', 'value' => '', 'autofocus' => false])
<form method="get" action="/search" class="toolbar-search search-field" role="search">
    <x-ui.icon name="search" class="size-4 shrink-0 text-ink-muted"/>
    <input type="search" name="q" value="{{ $value }}" placeholder="{{ $hint }}" enterkeyhint="search" autocomplete="off" @if ($autofocus) autofocus @endif aria-label="Поиск"
           data-global-search-target="input" data-action="input->global-search#input keydown->global-search#keydown">
    <button type="button" class="toolbar-search-x" data-global-search-target="clear" data-action="global-search#clear" aria-label="Очистить" @if ($value === '') hidden @endif><x-ui.icon name="x" class="size-4"/></button>
</form>
