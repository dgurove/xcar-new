{{-- Пустое состояние: фраза и, если есть куда, ссылка. --}}
@props(['href' => null, 'link' => null])
<div {{ $attributes->merge(['class' => 'empty']) }}>
    <p>{{ $slot }}</p>
    @if ($href && $link)<a href="{{ $href }}">{{ $link }}</a>@endif
</div>
