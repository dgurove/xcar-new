<x-ui.auth title="Вход">
    @if (session('status'))<x-ui.flash class="mt-5">{{ session('status') }}</x-ui.flash>@endif
    <form method="post" action="/vhod" class="mt-6 space-y-3">
        @csrf
        <input name="login" type="text" required autocomplete="username webauthn" inputmode="email" class="field-input" placeholder="Телефон или почта" value="{{ old('login') }}" autofocus>
        <input name="password" type="password" required autocomplete="current-password" class="field-input" placeholder="Пароль">
        @error('login')<p class="text-sm text-danger">{{ $message }}</p>@enderror
        @error('password')<p class="text-sm text-danger">{{ $message }}</p>@enderror
        <div class="px-1 text-sm text-ink-muted"><x-ui.check name="remember" :checked="true">Запомнить меня</x-ui.check></div>
        <button type="submit" class="btn btn-accent w-full">Войти</button>
    </form>
    <div data-controller="passkey" data-passkey-mode-value="login" hidden>
        <div class="my-4 flex items-center gap-3 text-sm text-ink-dim"><span class="h-px flex-1 bg-line"></span>или<span class="h-px flex-1 bg-line"></span></div>
        <button type="button" class="btn btn-quiet w-full" data-action="passkey#login" data-passkey-target="button"><x-ui.icon name="faceid" class="size-5"/> <span data-label>Войти по ключу</span></button>
    </div>
    <div class="mt-5 flex justify-between text-sm">
        <a href="/parol" class="text-ink-muted hover:text-accent-text">Забыли пароль?</a>
        <a href="/registraciya" class="text-accent-text hover:underline">Регистрация</a>
    </div>
</x-ui.auth>
