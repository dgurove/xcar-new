{{-- Оболочка страницы: шапка, содержимое с крошками и заголовком, подвал, таб-бар.
     Крошки и подвал — только на сайте: CRM и стоянка не для поисковиков, на
     телефоне вместо подвала — отступ под таб-бар.
     heading по умолчанию равен title; :heading="false" — без заголовка.
     Слот actions — ряд справа от h1 (поделиться, закладка, стрелки).
     back — ['Предложения', '/']: на телефоне слева в шапке «‹ Назад», на
     десктопе круглая «‹» слева от заголовка (x-ui.back). Вложенному экрану шелл ставит его сам (Nav::backFor);
     :back — уточнить, :back="false" — убрать; :back-row="false" — «назад» только в шапке телефона, без ряда на десктопе.
     :phone-heading="false" — на телефоне ряд с h1 спрятан: раздел уже назван в таб-баре, его место
     занимает лента пилюль тулбара (она же якорь для имени в шапке при прокрутке). --}}
@props(['title' => null, 'heading' => null, 'count' => null, 'trail' => [], 'overHero' => false, 'narrow' => false, 'back' => null, 'backRow' => true, 'phoneHeading' => true, 'cache' => 'no-preview'])
@php
    $heading = $heading === false ? null : ($heading ?? $title);
    // Пустой слот (кабинет передаёт его всегда) — всё равно что нет.
    if (isset($actions) && $actions->isEmpty()) { unset($actions); }
    $site = \App\Support\Surface::current() === \App\Support\Surface::Site;
    // Подвал и крошки — веб-мебель: в установленном приложении их нет (документы — в кабинете).
    $installed = \App\Http\Middleware\MarkInstalled::installed(request());
    $back = $back === false ? null : ($back ?? \App\Support\Nav::backFor('/'.ltrim(request()->path(), '/'), auth()->user()));
@endphp
<x-ui.layout :title="$title" :cache="$cache" class="min-h-dvh flex flex-col">
    <x-ui.header :over-hero="$overHero" :back="$back" :heading="$overHero ? null : ($heading ?? $title)"/>

    {{-- Запас под таб-бар: у CRM и стоянки всегда, у сайта — в приложении, где подвала с запасом нет. --}}
    <main id="main" class="grow {{ $overHero ? '' : 'relative' }}{{ $site && !$installed ? '' : ' main-app' }}">
        @if ($overHero)
            {{ $slot }}
        @else
            <div {{ $attributes->merge(['class' => 'container-site pt-6 pb-10 sm:pt-8 sm:pb-14'.($narrow ? ' max-w-3xl' : '')]) }}>
                @if ($site && !$installed)<x-ui.crumbs :trail="$trail"/>@endif
                @if ($heading || isset($actions) || ($back && $backRow))
                    {{-- Без заголовка и действий ряд нужен только ради круглой «‹» на десктопе: на телефоне она в шапке. --}}
                    <div @class(['has-back mb-6 flex-wrap items-center gap-x-4 gap-y-2', 'flex' => $heading || isset($actions), 'hidden sm:flex' => !$heading && !isset($actions), 'max-md:hidden' => !$phoneHeading])>
                        @if ($back)<x-ui.back :back="$back"/>@endif
                        @if ($heading)<h1 class="text-[28px] sm:text-[34px]">{{ $heading }}@if ($count !== null) <span class="nums ml-2 text-lg font-normal text-ink-dim">{{ $count }}</span>@endif</h1>@endif
                        @isset($actions)<div class="flex w-full flex-wrap items-center gap-1 sm:ml-auto sm:w-auto sm:gap-2">{{ $actions }}</div>@endisset
                    </div>
                @endif
                {{ $slot }}
            </div>
        @endif
    </main>

    @if ($site && !$installed)<x-ui.footer/>@endif
    <x-ui.tabbar/>
    <x-ui.toasts/>
</x-ui.layout>
