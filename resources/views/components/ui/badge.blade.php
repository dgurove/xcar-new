{{-- Счётчик на пункте таб-бара. Обёртка есть всегда, чтобы live мог её заменить. --}}
@props(['href', 'badges'])
<span id="{{ \App\Support\Nav::badgeId($href) }}" class="contents">@if (!empty($badges[$href]))<span class="badge">{{ $badges[$href] > 99 ? '99+' : $badges[$href] }}</span>@endif</span>
