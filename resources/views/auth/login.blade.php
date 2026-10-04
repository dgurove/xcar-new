<x-ui.auth title="Вход">
    @if (session('status'))<x-ui.flash class="mt-5">{{ session('status') }}</x-ui.flash>@endif
    <form method="post" action="/login" class="mt-6 space-y-3">
        @csrf
        <input name="login" type="text" required autocomplete="username webauthn" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next" data-check-text="Введите логин" class="field-input" placeholder="Логин, телефон или почта" value="{{ old('login') }}" autofocus>
        <input name="password" type="password" required autocomplete="current-password" enterkeyhint="go" data-check-text="Введите пароль" class="field-input" placeholder="Пароль">
        @error('login')<p class="text-sm text-danger">{{ $message }}</p>@enderror
        @error('password')<p class="text-sm text-danger">{{ $message }}</p>@enderror
        <div class="px-1 text-sm text-ink-muted"><x-ui.check name="remember" :checked="true">Запомнить меня</x-ui.check></div>
        <button type="submit" class="btn btn-accent w-full">Войти</button>
    </form>
    <div data-controller="passkey" data-passkey-mode-value="login" hidden>
        <div class="my-4 flex items-center gap-3 text-sm text-ink-dim"><span class="h-px flex-1 bg-line"></span>или<span class="h-px flex-1 bg-line"></span></div>
        <button type="button" class="btn btn-quiet w-full" data-action="passkey#login" data-passkey-target="button"><x-ui.icon name="faceid" class="size-5"/> <span data-label>Войти по ключу</span></button>
    </div>
    @if ($telegram)
        {{-- Ссылка на бота своя у этого браузера: подтверждённый в чате вход забирает только он. --}}
        <div data-controller="telegram" data-telegram-mode-value="login" data-telegram-token-value="{{ $telegramToken }}"
            data-telegram-app-value="{{ $telegramApp }}" data-telegram-web-value="{{ $telegram }}" class="mt-3 flex flex-col gap-1">
            <button type="button" class="btn btn-telegram w-full" data-action="telegram#go"><span class="contents" data-telegram-target="label"><x-telegram.logo plain/>Войти через Telegram</span></button>
            <button type="button" class="btn btn-ghost btn-s w-full" data-action="telegram#cancel" data-telegram-target="cancel" hidden>Отмена</button>
        </div>
    @endif
    <div class="mt-5 flex justify-between text-sm">
        <a href="/password" class="text-ink-muted hover:text-accent-text">Забыли пароль?</a>
        <a href="/register" class="text-ink-muted hover:text-accent-text">Нет аккаунта?</a>
    </div>
</x-ui.auth>
