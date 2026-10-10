{{-- Ссылка юридического текста на другую страницу сайта; plain — в шторке на оплате, где уходить на сайт нельзя. --}}
@props(['href', 'plain' => false])
@if ($plain)<span>{{ $slot }}</span>@else<a href="{{ $href }}">{{ $slot }}</a>@endif
