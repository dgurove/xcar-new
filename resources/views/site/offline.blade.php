<x-ui.layout title="Нет связи">
    <main class="flex min-h-dvh flex-col items-center justify-center gap-4 px-6 text-center">
        <x-ui.logo class="h-8 w-auto"/>
        <p class="text-ink-muted">Сети нет. Страница откроется, как только связь вернётся.</p>
        <button type="button" class="btn btn-quiet" onclick="location.reload()">Попробовать снова</button>
    </main>
</x-ui.layout>
