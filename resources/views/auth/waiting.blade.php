<x-ui.auth title="Доступ на подтверждении">
    <p class="mt-4 text-ink-muted">{{ $user->name }}, заявку рассмотрит администратор. Как решит — придёт уведомление.</p>
    <div class="mt-6 flex flex-col gap-2">
        <a href="/contacts" class="btn btn-accent w-full"><x-ui.icon name="mail" class="size-5"/> Написать нам</a>
        <form method="post" action="/logout">@csrf<button type="submit" class="btn btn-ghost w-full">Выйти</button></form>
    </div>
</x-ui.auth>
