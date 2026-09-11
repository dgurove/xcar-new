<x-ui.layout title="Нет связи">
    <main class="flex min-h-dvh flex-col items-center justify-center gap-4 px-6 text-center">
        <img src="/images/xcar.svg" alt="" class="h-8 dark:hidden"><img src="/images/xcar-white.svg" alt="" class="hidden h-8 dark:block">
        <p class="text-ink-muted">Сети нет. Страница откроется, как только связь вернётся.</p>
        <button type="button" class="btn btn-secondary" onclick="location.reload()">Попробовать снова</button>
    </main>
</x-ui.layout>
