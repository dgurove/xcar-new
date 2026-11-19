{{-- Сайт не ответил. Сеть есть — это выкладка: полминуты, спрашиваем `/up` и открываемся сами (18.11.2026). Сети нет —
     страница уходит, как только связь вернётся. --}}
<x-ui.layout title="Нет связи">
    <main class="flex min-h-dvh flex-col items-center justify-center gap-4 px-6 text-center">
        <x-ui.logo class="h-8 w-auto"/>
        <p class="text-ink-muted" id="offline-why">Сети нет, страница откроется, как только связь вернётся</p>
        <button type="button" class="btn btn-quiet" onclick="location.reload()">Попробовать снова</button>
        <script>
            addEventListener('online', () => location.reload(), { once: true });
            if (navigator.onLine !== false) {
                document.getElementById('offline-why').textContent = 'Обновляем XCar, страница откроется сама через минуту';
                setInterval(() => fetch('/up?t=' + Date.now(), { cache: 'no-store', credentials: 'omit' }).then((r) => r.ok && location.reload()).catch(() => {}), 2000);
            }
        </script>
    </main>
</x-ui.layout>
