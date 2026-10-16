{{-- Строка списка, открывающая шторку вмещающего data-controller="sheet": кнопка вида .row во всю ширину. --}}
<button type="button" {{ $attributes->merge(['class' => 'row w-full text-left', 'data-action' => 'sheet#open']) }}>{{ $slot }}</button>
