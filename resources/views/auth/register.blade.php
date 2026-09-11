<x-ui.auth title="Регистрация">
    <form method="post" action="/registraciya" class="mt-6 space-y-3">
        @csrf
        <input name="name" required autocomplete="name" class="field-input" placeholder="Имя и фамилия" value="{{ old('name') }}" autofocus>
        <input name="phone" type="tel" required inputmode="tel" autocomplete="tel" class="field-input" placeholder="Телефон" value="{{ old('phone') }}">
        <input name="email" type="email" inputmode="email" autocomplete="email" class="field-input" placeholder="Почта" value="{{ old('email') }}">
        <input name="password" type="password" required autocomplete="new-password" class="field-input" placeholder="Пароль, от восьми знаков">
        @foreach (['name', 'phone', 'email', 'password'] as $field)
            @error($field)<p class="text-sm text-danger">{{ $message }}</p>@enderror
        @endforeach
        <label class="flex items-start gap-2.5 px-1 pt-1 text-sm text-ink-muted">
            <span class="check mt-0.5"><input type="checkbox" name="consent" value="1" required></span>
            <span>Даю <a href="/soglasie" class="text-accent-text hover:underline">согласие на обработку персональных данных</a> и принимаю <a href="/soglashenie" class="text-accent-text hover:underline">условия</a></span>
        </label>
        <button type="submit" class="btn btn-accent w-full">Зарегистрироваться</button>
    </form>
    <p class="mt-5 text-center text-sm text-ink-muted">Уже есть аккаунт? <a href="/vhod" class="text-accent-text hover:underline">Войти</a></p>
</x-ui.auth>
