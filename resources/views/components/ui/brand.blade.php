{{-- Логотип приложения в шапке — знак с его иконки, в цвете темы: сайт — знак xcar,
     CRM — надпись CRM, стоянка — P. Высота — классом (h-10 / h-16). --}}
@props(['class' => 'h-10'])
@php
    $surface = \App\Support\Surface::current();
    [$name, $w, $h] = match ($surface) {
        \App\Support\Surface::Crm => ['crm', 1300, 335],
        \App\Support\Surface::Park => ['park', 960, 1024],
        default => ['xcar-mark', 64, 64],
    };
@endphp
<span class="on-light"><img src="/images/{{ $name }}.svg" alt="{{ $surface->label() }}" width="{{ $w }}" height="{{ $h }}" class="{{ $class }} w-auto"></span>
<span class="on-dark"><img src="/images/{{ $name }}-white.svg" alt="{{ $surface->label() }}" width="{{ $w }}" height="{{ $h }}" class="{{ $class }} w-auto"></span>
