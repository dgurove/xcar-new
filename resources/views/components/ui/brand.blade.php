{{-- Логотип приложения в шапке, в цвете темы (11.10.2026, владелец: шапки одного вида). wide — горизонтальный, для
     телефона: надписи XCAR, CRM, PARK, LOGISTICS (две последние — scripts/wordmarks.py); без него — компактный знак ПК:
     знак xcar, P, эвакуатор с машиной на платформе (логистика, 17.10.2026 — вместо «L»); у CRM надпись и там, и там. Высота — классом. --}}
@props(['class' => 'h-10', 'wide' => false])
@php
    $surface = \App\Support\Surface::current();
    [$name, $w, $h] = match ($surface) {
        \App\Support\Surface::Crm => ['crm', 1300, 335],
        \App\Support\Surface::Park => $wide ? ['park-wide', 1543, 334] : ['park', 960, 1024],
        \App\Support\Surface::Logistics => $wide ? ['logistics-wide', 2610, 334] : ['logistics', 1024, 1024],
        default => $wide ? ['xcar', 180, 45] : ['xcar-mark', 64, 64],
    };
@endphp
<span class="on-light"><img src="/images/{{ $name }}.svg" alt="{{ $surface->label() }}" width="{{ $w }}" height="{{ $h }}" class="{{ $class }} w-auto"></span>
<span class="on-dark"><img src="/images/{{ $name }}-white.svg" alt="{{ $surface->label() }}" width="{{ $w }}" height="{{ $h }}" class="{{ $class }} w-auto"></span>
