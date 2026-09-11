{{-- Кадр паркинга фоном: свой под тему; dark — всегда тёмный (вход). --}}
@props(['dark' => false])
<div {{ $attributes->merge(['class' => 'parking-photo'.($dark ? ' parking-photo--dark' : '')]) }} role="presentation"></div>
