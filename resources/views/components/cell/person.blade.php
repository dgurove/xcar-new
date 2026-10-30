{{-- Человек в ячейке таблицы: аватар, имя — с ширины from (уже — только аватар, имя в подсказке). Пусто — ничего. --}}
@props(['user' => null, 'from' => '2xl'])
@if ($user)
    @php
        $name = match ($from) {
            null => 'truncate',
            'lg' => 'hidden truncate lg:inline',
            'xl' => 'hidden truncate xl:inline',
            default => 'hidden truncate 2xl:inline',
        };
    @endphp
    <span class="face flex items-center gap-2" title="{{ $user->name }}"><x-ui.avatar :user="$user" :size="22"/><span class="{{ $name }}">{{ $user->name }}</span></span>
@endif
