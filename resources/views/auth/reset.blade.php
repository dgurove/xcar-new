<x-ui.auth title="Новый пароль">
    <form method="post" action="/parol/novyj" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-ui.field name="email" label="Почта" type="email" :value="$email" autocomplete="email" required/>
        <x-ui.field name="password" label="Пароль" type="password" autocomplete="new-password" autofocus required/>
        <x-ui.field name="password_confirmation" label="Ещё раз" type="password" autocomplete="new-password" required/>
        <x-ui.button block>Сохранить</x-ui.button>
    </form>
</x-ui.auth>
