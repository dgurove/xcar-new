@props(['title' => null])
@php $surface = \App\Support\Surface::current(); @endphp
<!doctype html>
<html lang="ru" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="theme-color" content="#ffffff">
    <meta name="view-transition" content="same-origin">
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
    {{-- Тема до первой отрисовки, иначе тёмная страница мигает белым. --}}
    <script>
        (() => {
            const saved = localStorage.getItem('theme');
            const dark = saved ? saved === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
            if (dark) document.documentElement.classList.add('dark');
            document.querySelector('meta[name="theme-color"]').content = dark ? '#121212' : '#ffffff';
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-controller="{{ trim('pwa '.$attributes->get('data-controller')) }}" {{ $attributes->except('data-controller')->merge(['class' => 'antialiased surface-'.$surface->value]) }}>
    {{ $slot }}
</body>
</html>
