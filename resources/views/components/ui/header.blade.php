{{-- Шапка xcar.ru. Телефон: квадраты (поиск, закладка, логотип, колокольчик, тема);
     в CRM и на стоянке поиска нет — слева знак приложения (x-ui.brand).
     Десктоп: знак и два ряда капсул — справочное и разделы. --}}
@props(['overHero' => false, 'back' => null, 'heading' => null])
@php
    $surface = \App\Support\Surface::current();
    $park = $surface === \App\Support\Surface::Park;
    $site = $surface === \App\Support\Surface::Site;
    $user = auth()->user();
    $path = '/'.ltrim(request()->path(), '/');
    $capsules = \App\Support\Nav::capsules($user);
    $top = \App\Support\Nav::top($user);
    $badges = \App\Support\Nav::badges($user);
    $searchAction = $park ? '/mashiny' : '/';
    $cabinet = '/lk';
    $isCurrent = fn (array $item) => \App\Support\Nav::isCurrent($item, $path);
@endphp
<header id="header" class="header{{ $overHero ? ' header--over' : '' }}">
    {{-- Телефон --}}
    <div class="container-site grid {{ $site ? 'grid-cols-[auto_1fr_auto]' : 'grid-cols-[1fr_auto]' }} items-center gap-3 py-2 md:hidden">
        @if ($back)
            {{-- В глубине раздела: слева «‹ Раздел», на сайте знак остаётся по центру. --}}
            <a href="{{ $back[1] }}" class="header-btn header-back {{ $site ? '' : 'justify-self-start' }}" data-controller="back" data-action="back#go" data-turbo-action="replace">
                <x-ui.icon name="chevron-left" class="-ml-1 size-5 shrink-0"/><span class="truncate">{{ $back[0] }}</span>
            </a>
            @if ($site)<a href="/" class="header-brand flex items-center justify-self-center" aria-label="XCar"><x-ui.brand wide class="h-10"/></a>@endif
        @elseif ($site)
            <div class="flex gap-1">
                <div class="contents" data-controller="sheet">
                    <button type="button" class="header-btn header-sq" data-action="sheet#open" aria-label="Поиск">
                        <x-ui.icon name="search" class="size-[18px]"/>
                    </button>
                    <x-ui.sheet id="search-sheet" title="Поиск" data-controller="search" data-search-url-value="/poisk">
                        <form method="get" action="/" data-turbo-action="replace" data-action="submit->search#submit">
                            <input type="search" name="q" value="{{ is_string(request('q')) ? request('q') : '' }}" class="field-input" placeholder="Марка, модель, VIN" enterkeyhint="search" autocomplete="off" autofocus data-search-target="input" data-action="input->search#input">
                        </form>
                        <div class="pills mt-3" data-search-target="recent" hidden></div>
                        <turbo-frame id="search-results" target="_top" class="mt-3 block" data-search-target="frame"></turbo-frame>
                    </x-ui.sheet>
                </div>
                @if ($user)
                    <a href="/lk/izbrannoe" class="header-btn header-sq relative" aria-label="Избранное">
                        <x-ui.icon name="bookmark" class="size-[18px]"/><x-ui.badge href="/lk/izbrannoe" :badges="$badges"/>
                    </a>
                @endif
            </div>
            <a href="/" class="header-brand flex items-center justify-self-center" aria-label="XCar"><x-ui.brand wide class="h-10"/></a>
        @else
            <a href="/" class="flex min-w-0 items-center justify-self-start" aria-label="XCar"><x-ui.brand class="h-10"/></a>
        @endif
        <div class="flex items-center gap-1">
            <span class="net" aria-live="polite"></span>
            @if ($user)
                <div class="contents header-sq-wrap" data-controller="sheet notifications">
                    <button type="button" class="header-btn header-sq relative" data-action="sheet#open notifications#refresh" aria-label="Уведомления">
                        <x-ui.icon name="bell" class="size-[18px]"/><x-ui.badge href="/lk/uvedomleniya" :badges="$badges"/>
                    </button>
                    <x-ui.sheet id="notifications-sheet" title="Уведомления">
                        <turbo-frame id="notifications-latest" src="/lk/uvedomleniya/svezhie" loading="lazy" data-notifications-target="frame" class="block min-h-32">
                            <x-ui.skeleton :rows="3"/>
                        </turbo-frame>
                        <div class="mt-4 flex gap-2">
                            <a href="/lk/uvedomleniya" class="btn btn-s btn-quiet flex-1">Все уведомления</a>
                        </div>
                    </x-ui.sheet>
                </div>
            @else
                <a href="/vhod" class="header-btn header-sq" aria-label="Войти"><x-ui.icon name="login" class="size-[18px]"/></a>
            @endif
            <button type="button" class="header-btn header-sq" data-controller="theme" data-action="theme#toggle" aria-label="Тема">
                <x-ui.icon name="sun" class="on-light size-[18px]"/><x-ui.icon name="moon" class="on-dark size-[18px]"/>
            </button>
        </div>
        {{-- Заголовок экрана собирается в шапку, когда h1 ушёл под неё (app.js → .is-past-title). --}}
        @if ($heading)<span class="header-title" aria-hidden="true">{{ $heading }}</span>@endif
    </div>

    {{-- Десктоп --}}
    <div class="container-site hidden grid-cols-[auto_1fr] items-start gap-1 py-2 md:grid">
        <a href="/" class="row-span-2 mr-1 flex shrink-0 items-center self-start" aria-label="XCar"><x-ui.brand class="h-16"/></a>
        <div class="header-row">
            <form method="get" action="{{ $searchAction }}" class="header-btn header-h header-search relative justify-start px-3" role="search" data-turbo-action="replace">
                <x-ui.icon name="search" class="size-4 shrink-0 text-ink-muted"/>
                <input type="search" name="q" value="{{ is_string(request('q')) ? request('q') : '' }}" placeholder="{{ $park ? 'VIN, госномер, марка' : 'Поиск объявления…' }}" class="w-28 sm:w-36 lg:w-52" aria-label="Поиск">
            </form>
            @foreach ($top as $item)
                <a href="{{ $item['href'] }}" class="header-btn header-h flex-1 px-5 text-sm" @if ($isCurrent($item)) aria-current="page" @endif @if (str_starts_with($item['href'], 'http')) data-turbo="false" @endif>{{ $item['label'] }}</a>
            @endforeach
            @if ($user)
                <a href="{{ $cabinet }}" class="header-btn header-h ml-auto max-w-[11rem] gap-2 px-4 text-sm" @if (str_starts_with($path, $cabinet)) aria-current="page" @endif>
                    <x-ui.avatar :user="$user" :size="20"/><span class="truncate">{{ $user->shortName() }}</span>
                </a>
                <form method="post" action="/vyhod" class="contents header-sq-wrap">@csrf
                    <button type="submit" class="header-btn header-btn-square header-h w-[1.875rem]" aria-label="Выйти"><x-ui.icon name="exit" class="size-4"/></button>
                </form>
            @else
                <a href="/vhod" class="header-btn header-h ml-auto px-5 text-sm">Войти</a>
                @if ($site)<a href="/registraciya" class="header-btn header-btn-cta header-h px-5 text-sm">Регистрация</a>@endif
            @endif
            <button type="button" class="header-btn header-btn-square header-h w-[1.875rem]" data-controller="theme" data-action="theme#toggle" aria-label="Тема">
                <x-ui.icon name="sun" class="on-light size-4"/><x-ui.icon name="moon" class="on-dark size-4"/>
            </button>
        </div>
        <div class="header-row">
            @foreach ($capsules as $item)
                <a href="{{ $item['href'] }}" class="header-btn header-h relative flex-1 px-5 text-sm" @if ($isCurrent($item)) aria-current="page" @endif @if (str_starts_with($item['href'], 'http')) data-turbo="false" @endif>
                    {{ $item['label'] }}<x-ui.badge :href="$item['href']" :badges="$badges"/>
                </a>
            @endforeach
            @if ($user)
                <div class="contents header-sq-wrap" data-controller="sheet notifications">
                    <button type="button" class="header-btn header-btn-square header-h relative w-[1.875rem]" data-action="sheet#open notifications#refresh" aria-label="Уведомления">
                        <x-ui.icon name="bell" class="size-4"/><x-ui.badge href="/lk/uvedomleniya" :badges="$badges"/>
                    </button>
                    <x-ui.sheet id="notifications-sheet-wide" title="Уведомления">
                        <turbo-frame id="notifications-latest-wide" src="/lk/uvedomleniya/svezhie" loading="lazy" data-notifications-target="frame" class="block min-h-32"></turbo-frame>
                        <div class="mt-4 flex gap-2">
                            <a href="/lk/uvedomleniya" class="btn btn-s btn-quiet flex-1">Все уведомления</a>
                        </div>
                    </x-ui.sheet>
                </div>
                @if ($site)
                    <a href="/lk/izbrannoe" class="header-btn header-btn-square header-h relative w-[1.875rem]" aria-label="Избранное">
                        <x-ui.icon name="bookmark" class="size-4"/><x-ui.badge href="/lk/izbrannoe" :badges="$badges"/>
                    </a>
                @endif
            @endif
        </div>
    </div>
</header>
