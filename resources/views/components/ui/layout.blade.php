@props(['title' => null, 'cache' => 'no-preview'])
@php
    $surface = \App\Support\Surface::current();
    // Тема: cookie на общий домен — одна на три приложения и известна серверу, поэтому
    // <html class="dark"> и цвет полосы приходят готовыми, без мигания и без подмены мета Turbo.
    $theme = in_array(request()->cookie('theme'), ['dark', 'light'], true) ? request()->cookie('theme') : null;
@endphp
<!doctype html>
<html lang="ru" class="h-full{{ $theme === 'dark' ? ' dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="theme-color" content="{{ $theme === 'dark' ? '#121212' : '#ffffff' }}">
    <meta name="view-transition" content="same-origin">
    {{-- Без превью из кэша: иначе advance на виденный адрес рисует снимок, а через миг молча
         подменяет свежим — скачок после анимации. Ответ обычно уже в руках (префетч). --}}
    <meta name="turbo-cache-control" content="{{ $cache }}">
    <meta name="turbo-refresh-method" content="morph">
    <meta name="turbo-refresh-scroll" content="preserve">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (config('xcar.mercure.subscriber_key'))
    <meta name="mercure-hub" content="/.well-known/mercure">
    <meta name="mercure-topics" content="{{ implode(',', \App\Live\Topics::for(auth()->user(), app(\App\Chats\GuestEnquiry::class)->chat(request())?->id)) }}">
    @endif
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ $surface->short() }}">
    {{-- Иначе iOS делает из цены «1 250 000» телефонную ссылку; номера телефонов — сами tel:. --}}
    <meta name="format-detection" content="telephone=no">
    <title>{{ $title ? "$title — " : '' }}{{ $surface->label() }}</title>
    <link rel="icon" href="/pwa/{{ $surface->value }}/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/pwa/{{ $surface->value }}/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" sizes="180x180" href="/pwa/{{ $surface->value }}/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <x-ui.startup/>
    @auth<meta name="badge-count" content="{{ auth()->user()->unreadCount() }}"><meta name="user-id" content="{{ auth()->id() }}">@endauth
    @if (config('xcar.vapid.public'))<meta name="vapid-key" content="{{ config('xcar.vapid.public') }}">@endif
    <link rel="preload" href="/fonts/onest-var.woff2" as="font" type="font/woff2" crossorigin>
    @if ($surface !== \App\Support\Surface::Site)
    <meta name="robots" content="noindex, nofollow">
    @endif
    @if (!$theme)
    {{-- Cookie ещё нет: тема по системе до первой отрисовки, иначе тёмная страница мигает белым. --}}
    <script>
        (() => {
            let saved = null;
            try { saved = localStorage.getItem('theme'); } catch {}
            const dark = saved ? saved === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
            if (dark) document.documentElement.classList.add('dark');
            document.querySelector('meta[name="theme-color"]').content = dark ? '#121212' : '#ffffff';
        })();
    </script>
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-controller="{{ trim('pwa '.$attributes->get('data-controller')) }}" {{ $attributes->except('data-controller')->merge(['class' => 'antialiased surface-'.$surface->value]) }}>
    {{ $slot }}
</body>
</html>
