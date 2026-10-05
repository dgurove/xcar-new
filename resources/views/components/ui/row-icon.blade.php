{{-- Кружок со значком в начале строки списка. tone: plain — серый (раздел, настройка), accent — лаймовый (действие:
     «Пригласить», «Новая группа»), soft — лайм приглушённый; open, urgent, danger, muted — деньги (оплачено, к оплате,
     просрочено, неважное). size: s — 36, строка «Настроек» и «Денег»; без него — 44. --}}
@props(['name', 'tone' => null, 'size' => null])
@php $brand = in_array($name, ['sbp', 'sberpay', 'card'], true); @endphp
{{-- Способ оплаты — знаком бренда (`x-ui.pay-mark`, одним цветом), а не самодельной иконкой. --}}
<span {{ $attributes->class(['row-icon', 'row-icon-'.($tone ?: 'plain'), 'row-icon-s' => $size === 's']) }}>@if ($brand)<x-ui.pay-mark :name="$name" :class="$size === 's' ? 'h-[18px] w-auto' : 'h-5 w-auto'"/>@else<x-ui.icon :name="$name" :class="$size === 's' ? 'size-[18px]' : 'size-5'"/>@endif</span>
