{{-- Кружок со значком в начале строки списка. tone: plain — серый (раздел, настройка), accent — лаймовый (действие:
     «Пригласить», «Новая группа»), soft — лайм приглушённый. --}}
@props(['name', 'tone' => 'plain'])
<span {{ $attributes->class(['row-icon', 'row-icon-'.$tone]) }}><x-ui.icon :name="$name" class="size-5"/></span>
