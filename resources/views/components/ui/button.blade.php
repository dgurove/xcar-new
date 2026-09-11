{{-- Кнопка кита. variant: primary (лайм), secondary (серая), ghost, danger, glass, hero;
     size: s; round — круглая иконка. --}}
@props(['variant' => 'primary', 'size' => null, 'href' => null, 'type' => 'submit', 'block' => false, 'round' => false])
@php
    $map = ['primary' => 'btn-accent', 'secondary' => 'btn-quiet', 'ghost' => 'btn-ghost', 'danger' => 'btn-danger', 'glass' => 'btn-glass', 'hero' => 'btn-hero btn-accent', 'accent' => 'btn-accent', 'quiet' => 'btn-quiet'];
    $class = 'btn '.($map[$variant] ?? 'btn-'.$variant).($size ? ' btn-'.($size === 'sm' ? 's' : $size) : '').($block ? ' btn-block' : '').($round ? ' btn-round' : '');
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</button>
@endif
