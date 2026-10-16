{{-- Выкладка: страница сама проверяет /up и возвращается, когда приложение поднялось. Ушли с неё — проверка снимается. --}}
<x-ui.layout title="Обновляемся">
    <main class="flex min-h-dvh flex-col items-center justify-center gap-4 px-6 text-center" data-deploying>
        <x-ui.logo class="h-8 w-auto"/>
        <p class="text-xl">Обновляем приложение, минуту</p>
        <script>
            (() => {
                const t = setInterval(() => {
                    if (!document.querySelector('[data-deploying]')) return clearInterval(t);
                    fetch('/up', { cache: 'no-store' }).then((r) => r.ok && location.reload()).catch(() => {});
                }, 10000);
            })();
        </script>
    </main>
</x-ui.layout>
