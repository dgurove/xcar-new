{{-- Номер на экране (убыток, госномер, VIN): нажатие по номеру или по иконке справа кладёт его в буфер
     (copy_controller, тост done); нажатие не уходит наверх — карточка и карточка строки не откроются.
     Форму задаёт класс снаружи (tag, chip, без класса). Текст — своим куском (`vincode-text`): в ячейке таблицы длинный
     номер сокращается «…» (30.10.2026, номера Согаза), в буфер идёт целиком. text — что показать, если в буфер идёт другое (короткий адрес ссылки). --}}
@props(['value', 'text' => null, 'done' => 'Номер в буфере', 'title' => 'Скопировать'])
@if ($value)
    <span {{ $attributes->merge(['class' => 'vincode nums']) }} role="button" tabindex="0" title="{{ $title }}" data-controller="copy" data-copy-text-value="{{ $value }}" data-copy-done-value="{{ $done }}" data-action="click->copy#copy:prevent:stop keydown.enter->copy#copy:prevent"><span class="vincode-text">{{ $text ?? $value }}</span><x-ui.icon name="copy" class="size-[1em]"/></span>
@endif
