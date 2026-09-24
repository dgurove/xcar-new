{{-- Страницы покупателя (анкета, пропуск, скан): свой экран без шапки и подвала — знак XCar сверху, узкая колонка
     на фоне страницы. Не карточка входа: это сервис, а не форма логина. --}}
@props(['title'])
<x-ui.layout :title="$title" class="pickup-page">
    <main class="pickup-main">
        <a href="/" class="pickup-logo" aria-label="XCar"><x-ui.logo class="h-7 w-auto"/></a>
        {{ $slot }}
    </main>
    <x-ui.toasts/>
</x-ui.layout>
