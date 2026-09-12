{{-- Без шапки и таб-бара: когда упало, база и сессия могут быть недоступны. --}}
<x-ui.layout title="Не получилось">
    <main class="flex min-h-dvh flex-col items-center justify-center gap-4 px-6 text-center">
        <x-ui.logo class="h-8 w-auto"/>
        <p class="text-xl">Что-то сломалось, мы уже смотрим</p>
        <button type="button" class="btn btn-quiet" onclick="location.reload()">Обновить</button>
    </main>
</x-ui.layout>
