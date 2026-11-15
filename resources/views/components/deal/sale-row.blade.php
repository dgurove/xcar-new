{{-- Строка «Продажи» (16.11.2026, владелец: «всё, чего не хватает для договора, — в блоке продажи, там же кто платит и покупатель»;
     вариант А макета): подпись серым слева, справа значение или красное слово («не выбран», «нет данных», «не внесён»), нажатие —
     туда, где вносят. `href` — ссылка (страница машины, фрейм `_top`), без него — кнопка (атрибуты: `data-action` и т. п.). --}}
@props(['label', 'value', 'tone' => null, 'href' => null])
@php $tag = $href ? 'a' : 'button'; @endphp
<{{ $tag }} @if ($href) href="{{ $href }}" data-turbo-frame="_top" @else type="button" @endif {{ $attributes->class('row sale-row') }}>
    <span class="sale-row-label">{{ $label }}</span>
    <span @class(['sale-row-value', 'text-danger' => $tone === 'danger', 'text-accent-text' => $tone === 'accent'])>{{ $value }}</span>
    <x-ui.chevron/>
</{{ $tag }}>
