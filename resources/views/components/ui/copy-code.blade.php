{{-- Номер на экране (убыток, госномер, VIN): нажатие по номеру или по иконке справа кладёт его в буфер
     (copy_controller, тост done); нажатие не уходит наверх — карточка и окошко строки не откроются.
     Форму задаёт класс снаружи (tag, chip, без класса). --}}
@props(['value', 'done' => 'Номер в буфере', 'title' => 'Скопировать'])
@if ($value)
    <span {{ $attributes->merge(['class' => 'vincode nums']) }} role="button" tabindex="0" title="{{ $title }}" data-controller="copy" data-copy-text-value="{{ $value }}" data-copy-done-value="{{ $done }}" data-action="click->copy#copy:prevent:stop keydown.enter->copy#copy:prevent">{{ $value }}<x-ui.icon name="copy" class="size-[1em]"/></span>
@endif
