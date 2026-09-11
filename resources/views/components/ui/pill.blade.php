{{-- Пилюля: состояние или ссылка-раздел. tone: open, urgent, closed, danger, accent, soft, plain. --}}
@props(['tone' => null, 'href' => null, 'current' => false, 'lg' => false])
@php $class = 'pill'.($tone ? ' pill-'.$tone : '').($lg ? ' pill-lg' : ''); @endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }} @if ($current) aria-current="true" @endif>{{ $slot }}</a>
@else
    <span {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</span>
@endif
