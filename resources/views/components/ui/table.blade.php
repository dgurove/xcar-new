{{-- Таблица списка (третий вид): плотные строки. Строка — <tr data-detail-key> со ссылкой a[data-detail-link] в фрейм
     карточки (x-ui.row-link, App\Support\Detail): нажатие по строке открывает карточку рядом — справа колонкой от 1024,
     нижним листом на телефоне (x-ui.shell :detail, detail_controller); view — вид списка: подробная (wide) на телефоне
     показывает все столбцы и листается вбок. --}}
@props(['head', 'id' => null, 'view' => null])
<div {{ $attributes->merge(['class' => 'table-wrap'.($view === \App\Support\ListView::WIDE ? ' is-wide' : '')]) }} @if ($id) id="{{ $id }}" @endif>
    <div class="table-box">
        <table class="table">
            <thead>{{ $head }}</thead>
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>
</div>
