{{-- Плитка-число: сколько чего; ссылкой в раздел, если есть href. --}}
@props(['value', 'label', 'href' => null])
@if ($href)<a href="{{ $href }}" {{ $attributes->merge(['class' => 'stat']) }}>@else<div {{ $attributes->merge(['class' => 'stat']) }}>@endif
    <span class="stat-value">{{ $value }}</span>
    <span class="stat-label">{{ $label }}</span>
@if ($href)</a>@else</div>@endif
