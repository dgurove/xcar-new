{{-- Заголовки раздела «Работа»: Сделки, Почта, Чаты. Активный — h1 со
     своим числом, у остальных — счётчик из Nav::badges (горящие, непрочитанные). --}}
@props(['current', 'count' => null])
@php
    $badges = \App\Support\Nav::badges(auth()->user());
    $titles = ['deals' => 'Сделки', 'mail' => 'Почта', 'chats' => 'Чаты'];
@endphp
<div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
    @foreach ($titles as $key => $label)
        @if ($key === $current)
            <x-ui.section-title level="h1" :count="$count">{{ $label }}</x-ui.section-title>
        @else
            <x-ui.section-title :href="'/work/'.$key" :current="false" :count="$badges['/work/'.$key] ?? null">{{ $label }}</x-ui.section-title>
        @endif
    @endforeach
</div>
