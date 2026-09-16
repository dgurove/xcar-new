{{-- Человек чипом: аватар и короткое имя. current — выделен цветом; prefix — знак перед аватаром («→» — кто пришёл). --}}
@props(['user', 'current' => false, 'full' => false, 'prefix' => null])
<span {{ $attributes->merge(['class' => 'chip person'.($current ? ' person-current' : '').($prefix ? ' pl-2' : '')]) }}>@if ($prefix)<span class="text-ink-muted">{{ $prefix }}</span>@endif<x-ui.avatar :user="$user" :size="20"/><span class="truncate">{{ $full ? $user->name : $user->shortName() }}</span></span>
