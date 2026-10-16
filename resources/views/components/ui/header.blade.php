{{-- Шапка всех четырёх хостов одним видом (11.10.2026, владелец: «разные, разного размера»). Телефон: логотип по
     центру, квадраты по бокам. Десктоп: знак и два ряда капсул — справочное и разделы; «назад» здесь только на телефоне,
     на десктопе он у заголовка (x-ui.back). --}}
@props(['back' => null, 'heading' => null])
@php
    $surface = \App\Support\Surface::current();
    $park = $surface === \App\Support\Surface::Park;
    $site = $surface === \App\Support\Surface::Site;
    $user = auth()->user();
    $path = request()->attributes->get('nav.tab') ?? '/'.ltrim(request()->path(), '/');
    $capsules = \App\Support\Nav::capsules($user);
    $top = \App\Support\Nav::top($user);
    $badges = \App\Support\Nav::badges($user);
    // Логотип ведёт вошедшего в его список, гостя — на первый экран.
    $home = $user ? $surface->home() : '/';
    $cabinet = \App\Support\Nav::cabinetRoot();
    $isCurrent = fn (array $item) => \App\Support\Nav::isCurrent($item, $path);
    // Чаты площадки в шапке CRM — на месте «Избранного» сайта; у модератора их нет.
    $crmChats = $surface === \App\Support\Surface::Crm && $user?->canManageCrm();
@endphp
<header id="header" class="header">
    {{-- Телефон: на всех хостах горизонтальный логотип ровно по центру (1fr | auto | 1fr), по бокам квадраты. Слева лупа и
         второе своё (сайт — избранное, CRM — чаты; у парковки и логистики второго нет), в глубине раздела — «‹» квадратом;
         справа колокольчик и тема. --}}
    <div class="container-site grid grid-cols-[1fr_auto_1fr] items-center gap-2 py-2 md:hidden">
        <div class="flex items-center gap-1 justify-self-start">
            @if ($back)
                <a href="{{ $back[1] }}" class="header-btn header-sq" aria-label="Назад" title="Назад" data-controller="back" data-action="back#go" data-turbo-action="replace">
                    <x-ui.icon name="chevron-left" class="-ml-px size-5"/>
                </a>
            @elseif ($user)
                <x-search.box phone/>
                @if ($site || $crmChats)<x-ui.header-btn :name="$site ? 'favorites' : 'chats'" :badges="$badges"/>@endif
            @endif
        </div>
        <a href="{{ $home }}" class="header-brand flex items-center justify-self-center" aria-label="XCar"><x-ui.brand wide class="h-10 max-w-[9.75rem] object-contain"/></a>
        <div class="flex items-center gap-1 justify-self-end">
            <span class="net" aria-live="polite"></span>
            @if ($user)
                <x-ui.header-btn name="notifications" :badges="$badges"/>
            @else
                <a href="/login" class="header-btn header-sq" aria-label="Войти"><x-ui.icon name="login" class="size-[18px]"/></a>
            @endif
            <x-ui.header-btn name="theme"/>
        </div>
        {{-- Заголовок экрана собирается в шапку, когда h1 ушёл под неё (app.js → .is-past-title). --}}
        @if ($heading)<span class="header-title" aria-hidden="true">{{ $heading }}</span>@endif
    </div>

    {{-- Десктоп --}}
    <div class="container-site hidden grid-cols-[auto_1fr] items-start gap-1 py-2 md:grid">
        {{-- Компактный знак на два ряда: xcar, P, L вписаны в одну коробку 64 px, чтобы были одного размера; у CRM — та же
             надпись, что на телефоне, ниже ростом и по центру рядов. --}}
        @if ($surface === \App\Support\Surface::Crm)
            <a href="{{ $home }}" class="row-span-2 mr-2 flex shrink-0 items-center self-center" aria-label="XCar"><x-ui.brand class="h-9"/></a>
        @else
            <a href="{{ $home }}" class="header-mark row-span-2 mr-2" aria-label="XCar"><x-ui.brand class="h-16"/></a>
        @endif
        <div class="header-row">
            {{-- Поиск — по закрытому: гостю не нужен. --}}
            @if ($user)
            <x-search.box/>
            @endif
            @foreach ($top as $item)
                <a href="{{ $item['href'] }}" class="header-btn header-h flex-1 px-5 text-sm" @if ($isCurrent($item)) aria-current="page" @endif @if (str_starts_with($item['href'], 'http')) data-turbo="false" @endif>{{ $item['label'] }}</a>
            @endforeach
            @if ($user)
                <a href="{{ $cabinet }}" class="header-btn header-h ml-auto max-w-[11rem] gap-2 px-4 text-sm" @if (\App\Support\Nav::inCabinet($path, $user, 'desktop')) aria-current="page" @endif>
                    <x-ui.avatar :user="$user" :size="20"/><span class="truncate">{{ $user->shortName() }}</span>
                </a>
                <form method="post" action="/logout" class="contents header-sq-wrap">@csrf
                    <button type="submit" class="header-btn header-btn-square header-h w-[1.875rem]" aria-label="Выйти"><x-ui.icon name="exit" class="size-4"/></button>
                </form>
            @else
                <a href="/login" class="header-btn header-btn-cta header-h ml-auto px-5 text-sm">Войти</a>
            @endif
            <x-ui.header-btn name="theme" wide/>
        </div>
        <div class="header-row">
            @foreach ($capsules as $item)
                <a href="{{ $item['href'] }}" class="header-btn header-h relative flex-1 px-5 text-sm" @if ($isCurrent($item)) aria-current="page" @endif @if (str_starts_with($item['href'], 'http')) data-turbo="false" @endif>
                    {{ $item['label'] }}<x-ui.badge :href="$item['href']" :badges="$badges"/>
                </a>
            @endforeach
            @if ($user)
                <x-ui.header-btn name="notifications" wide :badges="$badges"/>
                @if ($site || $crmChats)<x-ui.header-btn :name="$site ? 'favorites' : 'chats'" wide :badges="$badges"/>@endif
            @endif
        </div>
    </div>
</header>
