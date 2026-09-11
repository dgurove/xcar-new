<x-ui.auth title="Регистрация">
    <form method="post" action="/registraciya" class="flex flex-col gap-4">
        @csrf
        <x-ui.field name="name" label="Имя" autocomplete="name" autofocus required/>
        <x-ui.field name="phone" label="Телефон" type="tel" inputmode="tel" autocomplete="tel" placeholder="+7" required/>
        <x-ui.field name="email" label="Почта" type="email" inputmode="email" autocomplete="email"/>
        <x-ui.field name="password" label="Пароль" type="password" autocomplete="new-password" required/>
        <x-ui.button block>Зарегистрироваться</x-ui.button>
    </form>
    <p class="mt-5 text-center text-sm text-ink-muted">Уже есть аккаунт? <a href="/vhod" class="text-accent-text">Войти</a></p>
</x-ui.auth>
