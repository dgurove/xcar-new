{{-- Вход, регистрация, пароль: свой экран без шапки и подвала — фото паркинга,
     белый знак, карточка формы. Тема карточки — по общей теме, фото всегда тёмное. --}}
@props(['title'])
<x-ui.layout :title="$title" class="auth-page">
    <div class="fixed inset-0">
        <x-ui.photo-parking class="absolute inset-0" dark/>
        <div class="auth-shade absolute inset-0"></div>
    </div>
    <main class="relative flex min-h-dvh flex-col items-center justify-center px-4 py-12">
        <a href="/" class="mb-7 shrink-0" aria-label="XCar">
            <img src="/images/xcar-white.svg" alt="XCar" width="180" height="45" class="h-9 w-auto sm:h-10">
        </a>
        <div class="w-full max-w-md rounded-(--radius-xl) bg-surface p-6 text-ink shadow-(--shadow-drop) sm:p-8">
            <h1 class="text-2xl">{{ $title }}</h1>
            {{ $slot }}
        </div>
        @isset($footer)<p class="mt-5 text-center text-sm text-white/70">{{ $footer }}</p>@endisset
    </main>
    <x-ui.toasts/>
</x-ui.layout>
