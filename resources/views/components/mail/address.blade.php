{{-- Адрес почты, который видно и можно взять (владелец 07.10.2026: «саму почту его нигде невозможно посмотреть и
     скопировать — бесит»): нажатие кладёт адрес в буфер (copy_controller, тост) и не уходит наверх — строка, шапка
     письма (`<summary>`) и карточка не открываются и не сворачиваются. Иконка копирования — по наведению на ПК, на
     телефоне видна всегда. Длинный адрес режется в конце: это данные извне. --}}
@props(['email', 'done' => 'Адрес в буфере'])
@if ($email)
    <span {{ $attributes->class('mail-address') }} role="button" tabindex="0" title="{{ $email }}" data-controller="copy" data-copy-text-value="{{ $email }}" data-copy-done-value="{{ $done }}" data-action="click->copy#copy:prevent:stop keydown.enter->copy#copy:prevent:stop"><span class="mail-address-text">{{ $email }}</span><x-ui.icon name="copy" class="mail-address-icon size-[.95em]"/></span>
@endif
