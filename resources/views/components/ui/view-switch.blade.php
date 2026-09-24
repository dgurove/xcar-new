{{-- Вид списка: круглая кнопка с иконкой текущего вида, по нажатию — меню
     «Плитками · Строками · Таблицей · Подробной таблицей» (x-ui.menu). Подробная — только на телефоне:
     на ПК она совпадает с таблицей, и пункта там нет, а кнопка при ней рисует значок таблицы.
     Пока вид не выбран, его решает ширина экрана: две кнопки, видна одна. views — какие виды есть. --}}
@props(['views' => \App\Support\ListView::ALL, 'current' => false])
@php
    use App\Support\ListView;
    // current — вид, которым список открыт (ListView::pick): длинный без выбора — таблицей.
    $current = $current === false ? ListView::fromRequest(request()) : $current;
    $path = '/'.ltrim(request()->path(), '/');
    $url = fn (string $v) => $path.'?'.http_build_query(array_merge(request()->query(), [ListView::PARAM => $v]));
    $all = ['grid' => ['Плитками', 'grid-2'], 'list' => ['Строками', 'list'], 'table' => ['Таблицей', 'table'], 'wide' => ['Подробной таблицей', 'columns']];
    // Подробная без краткой не бывает: это та же таблица.
    $views = in_array(ListView::TABLE, $views, true) ? $views : array_diff($views, [ListView::WIDE]);
    $items = array_intersect_key($all, array_flip($views));
    // Без выбора — на телефоне строки, от 640 плитки (если плиток нет — строки); подробная на ПК — значком таблицы.
    $shown = match (true) {
        $current === ListView::WIDE => ['wide' => 'sm:hidden', 'table' => 'hidden sm:inline-flex'],
        (bool) $current => [$current => ''],
        isset($items['grid']) => ['list' => 'sm:hidden', 'grid' => 'hidden sm:inline-flex'],
        default => ['list' => ''],
    };
    $id = 'view-'.substr(md5($path), 0, 6);
@endphp
<div class="contents" data-controller="menu">
    @foreach ($shown as $view => $class)
        <button type="button" class="btn btn-s btn-quiet btn-round shrink-0 {{ $class }}" data-action="menu#toggle" aria-label="Вид списка" aria-haspopup="menu" aria-controls="{{ $id }}"><x-ui.icon :name="$all[$view][1]" class="size-5"/></button>
    @endforeach
    <div id="{{ $id }}" class="menu" popover data-menu-target="list" role="menu">
        @foreach ($items as $view => [$label, $icon])
            <a href="{{ $url($view) }}" @class(['menu-item', 'sm:hidden' => $view === ListView::WIDE]) role="menuitem" data-turbo-action="replace" data-action="menu#close" @if ($view === $current || (!$current && array_key_first($shown) === $view && count($shown) === 1)) aria-current="true" @endif><x-ui.icon :name="$icon" class="size-5"/>{{ $label }}</a>
        @endforeach
    </div>
</div>
