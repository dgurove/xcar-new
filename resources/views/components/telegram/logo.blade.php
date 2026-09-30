{{-- Знак Telegram: синий круг с самолётиком (tg-logo) или только самолётик цветом текста (plain — в синей кнопке). --}}
@props(['plain' => false])
@php $plane = 'M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z'; @endphp
@if ($plain)
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" {{ $attributes->merge(['class' => 'size-5 shrink-0']) }}><path d="{{ $plane }}"/></svg>
@else
<span {{ $attributes->merge(['class' => 'tg-logo size-10']) }} aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="{{ $plane }}"/></svg></span>
@endif
