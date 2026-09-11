{{-- Счётчик на пункте. Обёртка есть всегда, чтобы live мог её обновить по data-badge. --}}
@props(['href', 'badges'])
<span data-badge="{{ $href }}" class="contents">@if (!empty($badges[$href]))<span class="badge">{{ $badges[$href] > 99 ? '99+' : $badges[$href] }}</span>@endif</span>
