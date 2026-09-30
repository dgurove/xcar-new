{{-- Кружок со значком в начале строки списка. tone: plain — серый (раздел, настройка), accent — лаймовый (действие:
     «Пригласить», «Новая группа»), soft — лайм приглушённый; open, urgent, danger, muted — деньги (оплачено, к оплате,
     просрочено, неважное). size: s — 36, строка «Настроек» и «Денег»; без него — 44. --}}
@props(['name', 'tone' => null, 'size' => null])
<span {{ $attributes->class(['row-icon', 'row-icon-'.($tone ?: 'plain'), 'row-icon-s' => $size === 's']) }}><x-ui.icon :name="$name" :class="$size === 's' ? 'size-[18px]' : 'size-5'"/></span>
