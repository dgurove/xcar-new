{{-- Знак или горизонтальный логотип в двух цветах: показ по теме, а не подмена src. --}}
@props(['mark' => false, 'responsive' => false, 'class' => 'h-10 w-auto'])
@php
    $wide = ['/images/xcar.svg', '/images/xcar-white.svg', 180, 45];
    $sq = ['/images/xcar-mark.svg', '/images/xcar-mark-white.svg', 64, 64];
@endphp
@if ($responsive)
    <span class="on-light"><img src="{{ $sq[0] }}" alt="XCar" width="64" height="64" class="{{ $class }} sm:hidden"><img src="{{ $wide[0] }}" alt="XCar" width="180" height="45" class="hidden {{ $class }} sm:block"></span>
    <span class="on-dark"><img src="{{ $sq[1] }}" alt="XCar" width="64" height="64" class="{{ $class }} sm:hidden"><img src="{{ $wide[1] }}" alt="XCar" width="180" height="45" class="hidden {{ $class }} sm:block"></span>
@else
    @php [$light, $dark, $w, $h] = $mark ? $sq : $wide; @endphp
    <span class="on-light"><img src="{{ $light }}" alt="XCar" width="{{ $w }}" height="{{ $h }}" class="{{ $class }}"></span>
    <span class="on-dark"><img src="{{ $dark }}" alt="XCar" width="{{ $w }}" height="{{ $h }}" class="{{ $class }}"></span>
@endif
