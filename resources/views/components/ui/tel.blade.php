{{-- Номер телефона — одна дверь на все хосты (11.11.2026, владелец: «везде по нажатию на номер окошко — позвонить или
     скопировать», «кнопки „Позвонить“ везде убрать»): только номер, лаймовым текстом, без трубки и вида кнопки; нажатие —
     окошко `tel.js` («Скопировать» главная, «Позвонить»). value — как записан или нормализован (`Phone::normalize`); не
     телефон (иностранный, добавочный) — показывается как есть и звонит по цифрам. text — свой вид (например, как в письме). --}}
@props(['value', 'text' => null])
@php
    $digits = \App\Support\Phone::normalize($value);
    $href = $digits ? '+'.$digits : preg_replace('/[^\d+]/', '', (string) $value);
    $shown = $text ?? ($digits ? \App\Support\Phone::format($digits) : trim((string) $value));
@endphp
@if (trim((string) $value) !== '')<a href="tel:{{ $href }}" {{ $attributes->merge(['class' => 'tel nums whitespace-nowrap text-accent-text']) }}>{{ $shown }}</a>@endif
