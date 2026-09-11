{{-- Нижний таб-бар телефона: до четырёх разделов и «Кабинет» с аватаром. --}}
@props(['surface' => 'site'])
@php
    $user = auth()->user();
    $path = '/'.ltrim(request()->path(), '/');
    $tabs = \App\Support\Nav::tabs($user, $surface);
    $badges = \App\Support\Nav::badges($user, $surface);
@endphp
<nav class="tabbar" id="tabbar" aria-label="Разделы" data-controller="tabbar" data-tabbar-target="bar">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['href'] }}" class="tab" @if (\App\Support\Nav::isCurrent($tab, $path)) aria-current="page" @endif>
            @if ($loop->last && $user)
                <x-ui.avatar :user="$user" :size="25"/>
            @else
                <x-ui.icon :name="$tab['icon']" class="icon-line"/><x-ui.icon :name="$tab['icon']" fill class="icon-fill"/>
            @endif
            <span>{{ $tab['label'] }}</span>
            <x-ui.badge :href="$tab['href']" :badges="$badges"/>
        </a>
    @endforeach
</nav>
