{{-- Оболочка приложения: шапка, содержимое, таб-бар. --}}
@props(['title' => null, 'surface' => null, 'back' => null, 'wide' => false])
@php
    $user = auth()->user();
    $surface ??= \App\Http\Middleware\ParkHost::isPark(request()) ? 'park' : 'site';
    $tabs = \App\Support\Nav::tabs($user, $surface);
    $badges = \App\Support\Nav::badges($user, $surface);
    $path = '/'.ltrim(request()->path(), '/');
@endphp
<x-ui.layout :title="$title" data-controller="live">
    <header class="topbar" id="topbar">
        <div class="mx-auto flex h-(--spacing-header) max-w-(--container-site) items-center gap-2 px-4">
            <a href="{{ $surface === 'park' ? '/' : '/' }}" class="mr-2 flex items-center" aria-label="{{ config('app.name') }}">
                <img src="/images/xcar.svg" alt="" class="h-6 dark:hidden">
                <img src="/images/xcar-white.svg" alt="" class="hidden h-6 dark:block">
                @if ($surface === 'park')<span class="ml-2 text-sm text-ink-muted">стоянка</span>@endif
            </a>
            <nav class="hidden items-center gap-1 md:flex">
                @foreach ($tabs as $tab)
                    <a href="{{ $tab['href'] }}" class="navlink" @if (\App\Support\Nav::isCurrent($tab, $path)) aria-current="page" @endif>{{ $tab['label'] }} <x-ui.badge :href="$tab['href']" :badges="$badges"/></a>
                @endforeach
            </nav>
            <div class="ml-auto flex items-center gap-1">
                <button type="button" class="btn btn-ghost btn-sm px-2" data-controller="theme" data-action="theme#toggle" aria-label="Тема">
                    <x-ui.icon name="sun" class="size-5 dark:hidden"/><x-ui.icon name="moon" class="hidden size-5 dark:block"/>
                </button>
                @auth
                    <a href="{{ $surface === 'park' ? '/eshchyo' : ($user->isStaff() ? '/admin/eshchyo' : '/lk') }}" class="btn btn-ghost btn-sm px-2 hidden md:inline-flex">{{ $user->name }}</a>
                @else
                    <a href="/vhod" class="btn btn-secondary btn-sm hidden md:inline-flex">Войти</a>
                @endauth
            </div>
        </div>
    </header>

    <main {{ $attributes->merge(['class' => 'mx-auto w-full px-4 py-5 '.($wide ? 'max-w-(--container-site)' : 'max-w-3xl')]) }}
          style="padding-bottom: calc(var(--spacing-tabbar) + env(safe-area-inset-bottom) + 1.25rem)">
        @if ($back || $title)
            <div class="mb-4 flex items-center gap-2">
                @if ($back)<a href="{{ $back }}" class="btn btn-ghost btn-sm -ml-2 px-2 md:hidden" aria-label="Назад"><x-ui.icon name="chevron-left" class="size-5"/></a>@endif
                @if ($title)<h1 class="text-2xl">{{ $title }}</h1>@endif
            </div>
        @endif
        {{ $slot }}
    </main>

    <nav class="tabbar" id="tabbar" aria-label="Разделы">
        @foreach ($tabs as $tab)
            <a href="{{ $tab['href'] }}" class="tab" @if (\App\Support\Nav::isCurrent($tab, $path)) aria-current="page" @endif data-tab="{{ $tab['match'] }}">
                <x-ui.icon :name="$tab['icon']"/>
                <span>{{ $tab['label'] }}</span>
                <x-ui.badge :href="$tab['href']" :badges="$badges"/>
            </a>
        @endforeach
    </nav>

    <x-ui.toasts/>
</x-ui.layout>
