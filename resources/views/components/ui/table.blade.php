{{-- Таблица списка (третий вид): плотные строки. Строка — <tr data-detail-key> со ссылкой a[data-detail-link] в фрейм
     карточки (x-ui.row-link, App\Support\Detail): нажатие по строке открывает карточку рядом — справа колонкой от 1024,
     нижним листом на телефоне (x-ui.shell :detail, detail_controller); view — вид списка: подробная (wide) на телефоне
     показывает все столбцы и листается вбок. titles — названия строк: столбец «Марка, модель» (title-cap) шириной в самое
     длинное из них (или число знаков — когда за названием значки), чтобы они не сокращались, пока справа есть место; сжимается он, только когда места нет; rest — сколько
     rem занимают прочие столбцы (без него — 40). Не влезла в ширину — листается вбок (table_scroll_controller). table — описание
     столбцов (`Support\Table`): с ним на ПК длинная таблица (больше 200 строк) — сетка, и строка за экраном не считается
     (`Table::gridStyle`). --}}
@props(['head', 'id' => null, 'view' => null, 'titles' => null, 'rest' => null, 'table' => null])
@php
    $titleCh = $titles ? collect($titles)->map(fn ($t) => is_int($t) ? $t : mb_strlen((string) $t))->max() : null;
    $wide = $view === \App\Support\ListView::WIDE;
    // С описанием столбцов (`Support\Table`) на ПК — сеткой: строки за экраном не считаются (`Table::gridStyle`, `.is-grid`).
    // Только длинная (29.10.2026, «Заявки» в 66 строк: название — полэкрана пустоты, дата, почта и номер обрезаны): у сетки
    // ширины из описания, а не из содержимого, и шире их делает только скрипт после отрисовки. Короткую ширит браузер сам,
    // по содержимому; сетка окупается на сотнях строк («Все» CRM, 749 — карточка открывалась полсекунды). Ширины сетки — из
    // самих значений, на сервере (`Support\Table\Widths`): браузер при прокрутке ничего не меряет.
    $grid = $table && ! $wide && substr_count((string) $slot, 'data-detail-key=') > 200;
    $style = trim(($titleCh ? '--title-ch: '.$titleCh.';'.($rest ? ' --rest: '.$rest.'rem;' : '') : '').($grid ? ' '.$table->gridStyle(\App\Support\Table\Widths::of((string) $slot, $table)) : ''));
@endphp
<div {{ $attributes->merge(['class' => 'table-wrap'.($wide ? ' is-wide' : '').($grid ? ' is-grid' : ''), 'data-controller' => 'table-scroll']) }} @if ($id) id="{{ $id }}" @endif @if ($style) style="{{ $style }}" @endif>
    <div class="table-box">
        <table class="table">
            <thead>{{ $head }}</thead>
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>
</div>
