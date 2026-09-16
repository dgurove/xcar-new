<x-ui.auth title="Мы вас не узнали">
    <p class="mt-4 text-ink-muted">Попросите у своего менеджера новую пригласительную ссылку или напишите нам.</p>
    <div class="mt-6 flex flex-col gap-2">
        <a href="/contacts" class="btn btn-quiet w-full"><x-ui.icon name="mail" class="size-5"/> Написать нам</a>
        <form method="post" action="/logout">@csrf<button type="submit" class="btn btn-ghost w-full">Выйти</button></form>
    </div>
</x-ui.auth>
