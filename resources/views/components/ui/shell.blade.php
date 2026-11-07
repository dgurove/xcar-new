{{-- Оболочка страницы: шапка, содержимое с крошками и заголовком, подвал, таб-бар.
     Крошки и подвал — только на сайте: CRM и стоянка не для поисковиков, на
     телефоне вместо подвала — отступ под таб-бар.
     heading по умолчанию равен title; :heading="false" — без заголовка.
     Слот actions — ряд справа от h1 (поделиться, закладка, стрелки); lead — перед текстом h1 (логотип вендора),
     badge — сразу за ним (значок связи x-ui.links).
     back — ['Предложения', '/']: на телефоне слева в шапке «‹ Назад», на
     десктопе круглая «‹» слева от заголовка (x-ui.back). Вложенному экрану шелл ставит его сам: ПК — Nav::backFor,
     телефон — Nav::phoneBack (экран из меню кабинета — назад в кабинет, на ПК он раздел шапки);
     :back — уточнить (для обоих), :phone-back — только для телефона, :back="false" — убрать; :back-row="false" — «назад» только в шапке телефона, без ряда на десктопе.
     :desktop-heading="false" — ряд с h1 только на телефоне (кабинет: на ПК раздел называет пилюля).
     :phone-heading="false" — на телефоне ряд с h1 спрятан: раздел уже назван в таб-баре, его место
     занимает лента пилюль тулбара.
     :detail — список с карточкой строки рядом (App\Support\Detail): всё содержимое слева, фрейм detail справа
     колонкой на ПК и нижним листом на телефоне; пустой фрейм — список во всю ширину.
     stage — сцена во всю середину без контейнера, заголовка и прокрутки (космос на главной CRM, 04.11.2026): шапка,
     таб-бар и окна остаются.
     Документ не листается (22.10.2026): шапка, #scroller (содержимое и подвал) и таб-бар — три ряда колонки, листается
     только середина. Прокрутка документа в iOS 26–27 таскала fixed-слои за собой (WebKit 297779) — таб-бар уезжал до
     середины экрана; шапка снаружи стоит на месте, когда список отскакивает резинкой. --}}
@props(['title' => null, 'heading' => null, 'count' => null, 'trail' => [], 'narrow' => false, 'back' => null, 'phoneBack' => null, 'backRow' => true, 'phoneHeading' => true, 'desktopHeading' => true, 'cache' => 'no-preview', 'description' => null, 'index' => false, 'detail' => null, 'stage' => false])
@php
    $heading = $heading === false ? null : ($heading ?? $title);
    // Пустой слот (кабинет передаёт его всегда) — всё равно что нет.
    if (isset($actions) && $actions->isEmpty()) { unset($actions); }
    if (isset($lead) && $lead->isEmpty()) { unset($lead); }
    $site = \App\Support\Surface::current() === \App\Support\Surface::Site;
    // Партнёр — без шапки и таб-бара (08.11.2026): ряд заголовка с «‹» и на телефоне.
    $partner = \App\Support\Surface::current() === \App\Support\Surface::Partner;
    if ($partner) { $phoneHeading = true; }
    // Подвал и крошки — веб-мебель: в установленном приложении их нет (документы — в кабинете).
    $installed = \App\Http\Middleware\MarkInstalled::installed(request());
    $path = '/'.ltrim(request()->path(), '/');
    $phoneBack ??= $back === false ? null : ($back ?? \App\Support\Nav::phoneBack($path, auth()->user()));
    $back = $back === false ? null : ($back ?? \App\Support\Nav::backFor($path, auth()->user()));
@endphp
<x-ui.layout :title="$title" :cache="$cache" :description="$description" :index="$index" class="app-shell">
    <x-ui.header :back="$phoneBack"/>
    <div id="scroller" @class(['app-scroll', 'app-stage' => $stage])>

    {{-- Запас под таб-бар и плашку действий: везде, где нет подвала (у него свой запас) — CRM, стоянка, приложение
         и вошедший на сайте в браузере (подвал только у гостя). --}}
    @if ($stage)
    <main id="main" {{ $attributes->merge(['class' => 'stage']) }}>{{ $slot }}</main>
    @else
    <main id="main" class="grow relative{{ $site && !$installed && ! auth()->check() ? '' : ' main-app' }}">
        <div {{ $attributes->merge(['class' => 'container-site pt-6 pb-10 sm:pt-8 sm:pb-14'.($narrow ? ' max-w-3xl' : '')]) }}>
            @if ($detail)<div class="split" data-controller="detail" data-action="turbo:frame-load->detail#loaded keydown@window->detail#key click->detail#tap" @if ($detail->open()) data-open @endif><div class="split-main">@endif
            {{-- Крошки — веб-мебель для гостя и поисковиков; у вошедшего экран приложения, «назад» — в шапке. --}}
            @if ($site && !$installed && ! auth()->check())<x-ui.crumbs :trail="$trail"/>@endif
            @if ($heading || isset($actions) || ($back && $backRow))
                {{-- Без заголовка и действий ряд нужен только ради круглой «‹» на десктопе: на телефоне она в шапке. --}}
                <div @class(['has-back mb-4 flex-wrap items-center gap-x-4 gap-y-2', 'flex' => $heading || isset($actions), 'hidden md:flex' => !$heading && !isset($actions), 'max-md:hidden' => !$phoneHeading, 'md:hidden' => !$desktopHeading])>
                    @if ($back)<x-ui.back :back="$back" :phone="$partner"/>@endif
                    @if ($heading)<h1 class="head-title text-xl sm:text-2xl">@isset($lead){{ $lead }} @endisset{{ $heading }}{{ $badge ?? '' }}@if ($count !== null) <span class="nums ml-2 text-base font-normal text-ink-dim" data-shell-count>{{ $count }}</span>@endif</h1>@endif
                    @isset($actions)<div {{ $actions->attributes->merge(['class' => 'flex w-full flex-wrap items-center gap-1 sm:ml-auto sm:w-auto sm:gap-2']) }}>{{ $actions }}</div>@endisset
                </div>
            @endif
            {{ $slot }}
            @if ($detail)</div>{{ $detail->html() }}</div>@endif
        </div>
    </main>
    @endif

    {{-- Подвал — у сайта для гостя; у вошедшего экран приложения, документы в кабинете (Nav::cabinet). --}}
    @if ($site && !$installed && ! auth()->check())<x-ui.footer/>@endif
    </div>
    {{-- Партнёру (07.11.2026) — только просмотр документов: ни Telegram, ни пушей, ни сделок. --}}
    @auth
        @if (\App\Support\Surface::current() === \App\Support\Surface::Partner)
            <x-ui.docs/>
        @else
            <x-ui.docs/><x-telegram.connect/><x-push.offer/><x-users.credentials/><div id="handed-host" class="contents"><x-deal.handed/></div>
        @endif
    @endauth
    {{-- Новые лоты Мигторга черновиками — капсула сотрудникам CRM (админы и модераторы, тема `staff`). --}}
    @if (auth()->user()?->isStaff() && \App\Support\Surface::current() === \App\Support\Surface::Crm)<x-migtorg.island/>@endif
    @unless ($partner)<x-ui.tabbar/>@endunless
    <x-ui.toasts/>
</x-ui.layout>
