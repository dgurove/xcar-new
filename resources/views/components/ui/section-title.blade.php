{{-- Заголовок раздела со счётчиком. Несколько подряд — переключатель разделов:
     активный тёмный, остальные серые, размер один. --}}
@props(['count' => null, 'href' => null, 'current' => true, 'level' => 'h2'])
@php $class = 'text-xl transition-colors sm:text-2xl '.($current ? 'text-ink' : 'text-ink-dim hover:text-ink-muted'); @endphp
<{{ $level }} {{ $attributes->merge(['class' => $href ? '' : $class]) }}>
    @if ($href)<a href="{{ $href }}" class="{{ $class }}">{{ $slot }}@if ($count !== null) <span class="nums ml-1 text-base font-normal text-ink-dim">{{ $count }}</span>@endif</a>
    @else{{ $slot }}@if ($count !== null) <span class="nums ml-2 text-base font-normal text-ink-dim">{{ $count }}</span>@endif
    @endif
</{{ $level }}>
