{{-- Метка спорного поля (`Cars\Sources\Variants`, 07.11.2026) — под значением в виде текстом и под полем ввода: «или Белый ›»,
     у пустого — «Белый или Черный ›»; нажатие — шторка вариантов поля (`x-car.variants`). plain — только слова, без кнопки
     (строка списка, которая сама кнопка). --}}
@props(['v' => null, 'car' => null, 'plain' => false])
@if ($v)
    @php
        $labels = array_column($v['options'], 'label');
        $text = $v['current'] ? 'или '.implode(', ', $labels) : implode(', ', array_slice($labels, 0, -1)).' или '.end($labels);
    @endphp
    @if ($plain)
        <span class="variant-hint">{{ $text }}</span>
    @else
        <button type="button" {{ $attributes->class(['variant-hint']) }} data-controller="car-variants" data-action="car-variants#open"
            data-car-variants-sheet-param="variants-{{ $v['field'] }}-{{ $car }}"><span class="min-w-0 truncate">{{ $text }}</span><x-ui.icon name="chevron-right" class="size-3.5 shrink-0"/></button>
    @endif
@endif
