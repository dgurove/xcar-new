{{-- Аватар собеседника в чате: человек — x-ui.avatar, площадка (user пуст) — знак приложения. --}}
@props(['user' => null, 'size' => 40, 'online' => false])
@if ($user)
    <x-ui.avatar :user="$user" :size="$size" :online="$online" {{ $attributes }}/>
@else
    <span {{ $attributes->merge(['class' => 'avatar avatar-xcar'.($online ? ' is-online' : '')]) }} style="width: {{ $size }}px; height: {{ $size }}px"><img src="/pwa/site/icon-maskable-512.png" alt="" width="{{ $size }}" height="{{ $size }}"></span>
@endif
