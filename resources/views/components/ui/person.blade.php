{{-- Человек чипом: аватар и короткое имя. current — выделен цветом. --}}
@props(['user', 'current' => false, 'full' => false])
<span {{ $attributes->merge(['class' => 'chip person'.($current ? ' person-current' : '')]) }}><x-ui.avatar :user="$user" :size="20"/><span class="truncate">{{ $full ? $user->name : $user->shortName() }}</span></span>
