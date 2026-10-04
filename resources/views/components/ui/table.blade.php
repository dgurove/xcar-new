{{-- Таблица списка (третий вид): плотные строки. Строка — <tr data-detail-key> со ссылкой a[data-detail-link] в фрейм
     карточки (x-ui.row-link, App\Support\Detail): нажатие по строке открывает карточку рядом — справа колонкой от 1024,
     нижним листом на телефоне (x-ui.shell :detail, detail_controller); view — вид списка: подробная (wide) на телефоне
     показывает все столбцы и листается вбок. titles — названия строк: столбец «Марка, модель» (title-cap) шириной в самое
     длинное из них, чтобы они не сокращались, пока справа есть место; сжимается он, только когда места нет; rest — сколько
     rem занимают прочие столбцы (без него — 40). --}}
@props(['head', 'id' => null, 'view' => null, 'titles' => null, 'rest' => null])
@php $titleCh = $titles ? collect($titles)->map(fn ($t) => mb_strlen((string) $t))->max() : null; @endphp
<div {{ $attributes->merge(['class' => 'table-wrap'.($view === \App\Support\ListView::WIDE ? ' is-wide' : '')]) }} @if ($id) id="{{ $id }}" @endif @if ($titleCh) style="--title-ch: {{ $titleCh }};@if ($rest) --rest: {{ $rest }}rem;@endif" @endif>
    <div class="table-box">
        <table class="table">
            <thead>{{ $head }}</thead>
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>
</div>
