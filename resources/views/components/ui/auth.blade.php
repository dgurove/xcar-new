@props(['title'])
<x-ui.layout :title="$title">
    <main class="mx-auto flex min-h-dvh w-full max-w-sm flex-col justify-center gap-6 px-4 py-8">
        <a href="/" class="flex justify-center">
            <img src="/images/xcar.svg" alt="XCar" class="h-8 dark:hidden">
            <img src="/images/xcar-white.svg" alt="XCar" class="hidden h-8 dark:block">
        </a>
        <div class="box">
            <h1 class="mb-5 text-xl">{{ $title }}</h1>
            {{ $slot }}
        </div>
        @isset($footer)<p class="text-center text-sm text-ink-muted">{{ $footer }}</p>@endisset
    </main>
    <x-ui.toasts/>
</x-ui.layout>
