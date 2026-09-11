<x-ui.auth title="Восстановление пароля">
    <form method="post" action="/parol" class="flex flex-col gap-4">
        @csrf
        <x-ui.field name="email" label="Почта" type="email" inputmode="email" autocomplete="email" autofocus required/>
        <x-ui.button block>Отправить ссылку</x-ui.button>
    </form>
    <p class="mt-5 text-center text-sm"><a href="/vhod" class="text-ink-muted">Вернуться ко входу</a></p>
</x-ui.auth>
