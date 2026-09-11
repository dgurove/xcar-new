{{-- Оболочка страницы: шапка, содержимое с крошками и заголовком, подвал, таб-бар.
     heading по умолчанию равен title; :heading="false" — без заголовка.
     Слот actions — ряд справа от h1 (поделиться, закладка, стрелки). --}}
@props(['title' => null, 'heading' => null, 'count' => null, 'trail' => [], 'overHero' => false, 'surface' => null, 'wide' => false, 'back' => null, 'narrow' => false])
@php
    $surface ??= \App\Http\Middleware\ParkHost::isPark(request()) ? 'park' : 'site';
    $heading = $heading === false ? null : ($heading ?? $title);
@endphp
<x-ui.layout :title="$title" data-controller="live" class="min-h-dvh flex flex-col">
    <x-ui.header :surface="$surface" :over-hero="$overHero"/>

    <main id="main" class="grow {{ $overHero ? '' : 'relative' }}">
        @if ($overHero)
            {{ $slot }}
        @else
            <div {{ $attributes->merge(['class' => 'container-site pt-6 pb-10 sm:pt-8 sm:pb-14'.($narrow ? ' max-w-3xl' : '')]) }}>
                <x-ui.crumbs :trail="$trail"/>
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

    <x-ui.footer :surface="$surface"/>
    <x-ui.tabbar :surface="$surface"/>
    <x-ui.toasts/>
</x-ui.layout>
