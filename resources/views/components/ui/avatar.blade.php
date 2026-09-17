{{-- Кружок пользователя: фото или инициалы. online — зелёная точка в углу («в сети»). --}}
@props(['user', 'size' => 40, 'online' => false])
@php $url = $user?->avatarUrl(); @endphp
<span {{ $attributes->merge(['class' => 'avatar'.($online ? ' is-online' : '')]) }} style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ max(9, (int) round($size * .4)) }}px">
    @if ($url)<img src="{{ $url }}" alt="" width="{{ $size }}" height="{{ $size }}">@else{{ $user?->initials() ?? '·' }}@endif
</span>
