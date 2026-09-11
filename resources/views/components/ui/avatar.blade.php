{{-- Кружок пользователя: фото или инициалы. --}}
@props(['user', 'size' => 40])
@php $url = $user?->avatarUrl(); @endphp
<span {{ $attributes->merge(['class' => 'avatar']) }} style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ max(9, (int) round($size * .4)) }}px">
    @if ($url)<img src="{{ $url }}" alt="" width="{{ $size }}" height="{{ $size }}">@else{{ $user?->initials() ?? '·' }}@endif
</span>
