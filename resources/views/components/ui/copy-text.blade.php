{{-- Название, которое копируют (09.11.2026, владелец: «не могу наименование скопировать, и в окошке, и в редакторе,
     приходится перепечатывать»): нажатие по тексту кладёт его в буфер (copy_controller, тост done), без значка — текст
     остаётся текстом и переносится как обычно. Нажатие не уходит наверх. value — что в буфер, иначе сам текст слота. --}}
@props(['value' => null, 'done' => 'Название в буфере'])
@php $text = $value ?? trim(strip_tags((string) $slot)); @endphp
<span {{ $attributes->merge(['class' => 'copy-text']) }} role="button" tabindex="0" title="Скопировать" data-controller="copy" data-copy-text-value="{{ $text }}" data-copy-done-value="{{ $done }}" data-action="click->copy#copy:prevent:stop keydown.enter->copy#copy:prevent">{{ trim($slot) !== '' ? $slot : $value }}</span>
