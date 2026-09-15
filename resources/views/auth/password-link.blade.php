<x-ui.auth title="Новый пароль">
    @if ($live)
        <p class="mt-4 text-ink-muted">{{ $user->name }}, придумайте пароль — с ним вы будете входить как <span class="nums">{{ $user->loginLabel() }}</span>.</p>
        <form method="post" action="/parol/ssylka/{{ $token }}" class="mt-6 space-y-3">
            @csrf
            <input name="password" type="password" required autocomplete="new-password" class="field-input" placeholder="Новый пароль, от восьми знаков" autofocus>
            <input name="password_confirmation" type="password" required autocomplete="new-password" class="field-input" placeholder="Ещё раз">
            @error('password')<p class="text-sm text-danger">{{ $message }}</p>@enderror
            <button type="submit" class="btn btn-accent w-full">Сохранить и войти</button>
        </form>
    @else
        <p class="mt-4 text-ink-muted">Ссылка уже не действует — попросите новую у того, кто её прислал.</p>
        <a href="/vhod" class="btn btn-accent mt-6 w-full">Войти</a>
    @endif
</x-ui.auth>
