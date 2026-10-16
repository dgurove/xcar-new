{{-- Подвал шторки с одной кнопкой во всю ширину: слот — надпись, атрибуты — кнопке (variant, type, data-*), foot — классы
     самому подвалу. Подвалы с двумя кнопками и своей разметкой пишутся как есть. --}}
@props(['foot' => null])
<div class="sheet-foot{{ $foot ? ' '.$foot : '' }}"><x-ui.button block :attributes="$attributes">{{ $slot }}</x-ui.button></div>
