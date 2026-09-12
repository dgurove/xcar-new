{{-- Выкладка: страница сама проверяет /up и возвращается, когда приложение поднялось. --}}
<x-ui.layout title="Обновляемся">
    <main class="flex min-h-dvh flex-col items-center justify-center gap-4 px-6 text-center">
        <x-ui.logo class="h-8 w-auto"/>
        <p class="text-xl">Обновляем приложение, минуту</p>
        <script>
            setInterval(() => fetch('/up', { cache: 'no-store' }).then((r) => r.ok && location.reload()).catch(() => {}), 10000);
        </script>
    </main>
</x-ui.layout>
