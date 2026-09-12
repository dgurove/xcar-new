{{-- Оболочка страницы: шапка, содержимое с крошками и заголовком, подвал, таб-бар.
     Крошки и подвал — только на сайте: CRM и стоянка не для поисковиков, на
     телефоне вместо подвала — отступ под таб-бар.
     heading по умолчанию равен title; :heading="false" — без заголовка.
     Слот actions — ряд справа от h1 (поделиться, закладка, стрелки).
     back — ['Предложения', '/']: на телефоне слева в шапке «‹ Предложения»,
     как у экрана в глубине нативного приложения. --}}
@props(['title' => null, 'heading' => null, 'count' => null, 'trail' => [], 'overHero' => false, 'narrow' => false, 'back' => null])
@php
    $heading = $heading === false ? null : ($heading ?? $title);
    $site = \App\Support\Surface::current() === \App\Support\Surface::Site;
@endphp
<x-ui.layout :title="$title" data-controller="live" class="min-h-dvh flex flex-col">
    <x-ui.header :over-hero="$overHero" :back="$back"/>

    <main id="main" class="grow {{ $overHero ? '' : 'relative' }}{{ $site ? '' : ' pb-[calc(var(--spacing-tabbar)+env(safe-area-inset-bottom))] md:pb-0' }}">
        @if ($overHero)
            {{ $slot }}
        @else
            <div {{ $attributes->merge(['class' => 'container-site pt-6 pb-10 sm:pt-8 sm:pb-14'.($narrow ? ' max-w-3xl' : '')]) }}>
                @if ($site)<x-ui.crumbs :trail="$trail"/>@endif
                @if ($heading || isset($actions))
                    <div class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-2">
                        @if ($heading)<h1 class="text-[28px] sm:text-[34px]">{{ $heading }}@if ($count !== null) <span class="nums ml-2 text-lg font-normal text-ink-dim">{{ $count }}</span>@endif</h1>@endif
                        @isset($actions)<div class="flex w-full flex-wrap items-center gap-1 sm:ml-auto sm:w-auto sm:gap-2">{{ $actions }}</div>@endisset
                    </div>
                @endif
                {{ $slot }}
            </div>
        @endif
    </main>

    @if ($site)<x-ui.footer/>@endif
    <x-ui.tabbar/>
    <x-ui.toasts/>
</x-ui.layout>
