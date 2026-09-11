<x-ui.auth title="Вход">
    <form method="post" action="/vhod" class="flex flex-col gap-4">
        @csrf
        <x-ui.field name="login" label="Телефон или почта" inputmode="email" autocomplete="username" autofocus required/>
        <x-ui.field name="password" label="Пароль" type="password" autocomplete="current-password" required/>
        <x-ui.check name="remember" :checked="true">Запомнить меня</x-ui.check>
        <x-ui.button block>Войти</x-ui.button>
    </form>

    <div data-controller="passkey" data-passkey-mode-value="login" hidden data-passkey-target="root">
        <div class="my-4 flex items-center gap-3 text-sm text-ink-dim"><span class="h-px flex-1 bg-line"></span>или<span class="h-px flex-1 bg-line"></span></div>
        <x-ui.button type="button" variant="secondary" block data-action="passkey#login">
            <x-ui.icon name="faceid" class="size-5"/> Войти по Face ID
        </x-ui.button>
    </div>

    <div class="mt-5 flex justify-between text-sm">
        <a href="/parol" class="text-ink-muted">Забыли пароль?</a>
        <a href="/registraciya" class="text-accent-text">Регистрация</a>
    </div>
</x-ui.auth>
