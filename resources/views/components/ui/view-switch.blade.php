{{-- Вид списка: круглая кнопка с иконкой текущего вида, по нажатию — меню
     «Плитками · Строками · Таблицей» (x-ui.menu). Пока вид не выбран, его
     решает ширина экрана: две кнопки, видна одна. views — какие виды есть. --}}
@props(['views' => \App\Support\ListView::ALL, 'current' => false])
@php
    // current — вид, которым список открыт (ListView::pick): длинный без выбора — таблицей.
    $current = $current === false ? \App\Support\ListView::fromRequest(request()) : $current;
    $path = '/'.ltrim(request()->path(), '/');
    $url = fn (string $v) => $path.'?'.http_build_query(array_merge(request()->query(), [\App\Support\ListView::PARAM => $v]));
    $all = ['grid' => ['Плитками', 'grid-2'], 'list' => ['Строками', 'list'], 'table' => ['Таблицей', 'table']];
    $items = array_intersect_key($all, array_flip($views));
    // Без выбора — на телефоне строки, от 640 плитки (если плиток нет — строки).
    $shown = $current ? [$current => ''] : (isset($items['grid']) ? ['list' => 'sm:hidden', 'grid' => 'hidden sm:inline-flex'] : ['list' => '']);
    $id = 'view-'.substr(md5($path), 0, 6);
@endphp
<div class="contents" data-controller="menu">
    @foreach ($shown as $view => $class)
        <button type="button" class="btn btn-s btn-quiet btn-round shrink-0 {{ $class }}" data-action="menu#toggle" aria-label="Вид списка" aria-haspopup="menu" aria-controls="{{ $id }}"><x-ui.icon :name="$items[$view][1]" class="size-5"/></button>
    @endforeach
    <div id="{{ $id }}" class="menu" popover data-menu-target="list" role="menu">
        @foreach ($items as $view => [$label, $icon])
            <a href="{{ $url($view) }}" class="menu-item" role="menuitem" data-turbo-action="replace" data-action="menu#close" @if ($view === $current || (!$current && array_key_first($shown) === $view && count($shown) === 1)) aria-current="true" @endif><x-ui.icon :name="$icon" class="size-5"/>{{ $label }}</a>
        @endforeach
    </div>
</div>
