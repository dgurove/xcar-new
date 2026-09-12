<x-ui.auth title="Мы вас не узнали">
    <p class="mt-4 text-ink-muted">Повторите регистрацию с настоящими именем, телефоном и почтой или напишите нам.</p>
    <div class="mt-6 flex flex-col gap-2">
        <form method="post" action="/registraciya/zanovo">@csrf<button type="submit" class="btn btn-accent w-full">Зарегистрироваться заново</button></form>
        <a href="/kontakty" class="btn btn-quiet w-full"><x-ui.icon name="mail" class="size-5"/> Написать нам</a>
        <form method="post" action="/vyhod">@csrf<button type="submit" class="btn btn-ghost w-full">Выйти</button></form>
    </div>
</x-ui.auth>
