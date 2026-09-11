@props(['variant' => 'primary', 'size' => null, 'href' => null, 'type' => 'submit', 'block' => false])
@php $class = 'btn btn-'.$variant.($size ? ' btn-'.$size : '').($block ? ' btn-block' : ''); @endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</button>
@endif
