{{-- Страницы покупателя (анкета, пропуск, скан): свой экран без шапки и подвала — знак XCar сверху, узкая колонка
     на фоне страницы. Не карточка входа: это сервис, а не форма логина. home=false — знак не ведёт на сайт (оплата по ссылке). --}}
@props(['title', 'home' => true])
<x-ui.layout :title="$title" class="pickup-page">
    <main class="pickup-main">
        @if ($home)<a href="/" class="pickup-logo" aria-label="XCar"><x-ui.logo class="h-7 w-auto"/></a>@else<span class="pickup-logo" aria-label="XCar"><x-ui.logo class="h-7 w-auto"/></span>@endif
        {{ $slot }}
    </main>
    <x-ui.toasts/>
</x-ui.layout>
