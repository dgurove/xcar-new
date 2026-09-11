@props(['title' => null])
<!doctype html>
<html lang="ru" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="view-transition" content="same-origin">
    <meta name="turbo-refresh-method" content="morph">
    <meta name="turbo-refresh-scroll" content="preserve">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (config('xcar.mercure.subscriber_key'))
    <meta name="mercure-hub" content="/.well-known/mercure">
    <meta name="mercure-topics" content="{{ implode(',', \App\Live\Topics::for(auth()->user())) }}">
    @endif
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <title>{{ $title ? "$title — " : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="/images/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/pwa/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    @auth<meta name="badge-count" content="{{ auth()->user()->unreadCount() }}">@endauth
    @if (config('xcar.vapid.public'))<meta name="vapid-key" content="{{ config('xcar.vapid.public') }}">@endif
    <link rel="preload" href="/fonts/onest-var.woff2" as="font" type="font/woff2" crossorigin>
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
<body data-controller="{{ trim('pwa '.$attributes->get('data-controller')) }}" {{ $attributes->except('data-controller')->merge(['class' => 'min-h-full antialiased']) }}>
    {{ $slot }}
</body>
</html>
