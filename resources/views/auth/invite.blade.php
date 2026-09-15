{{-- Регистрация по приглашению: сверху менеджер, ниже только те поля, что он разрешил. --}}
@php($manager = $invite->manager)
<x-ui.auth :title="$invite->isActive() ? $manager->shortName().' приглашает вас в xcar' : 'Ссылка не действует'">
    @if (! $invite->isActive())
        <p class="mt-4 text-ink-muted">Попросите у {{ $manager->shortName() }} новую ссылку.</p>
        <a href="/vhod" class="btn btn-quiet mt-6 w-full">У меня уже есть аккаунт</a>
    @else
        <div class="mt-5 flex items-center gap-3">
            <x-ui.avatar :user="$manager" :size="48" class="text-lg"/>
            <div class="min-w-0">
                <div class="truncate font-medium">{{ $manager->name }}</div>
                <div class="text-sm text-ink-muted">ваш менеджер</div>
            </div>
        </div>
        @if ($preview)
            <p class="mt-5 text-sm text-ink-muted">Так ссылку видит покупатель. Регистрируется он сам — с телефона, по вашей ссылке.</p>
        @else
        <form method="post" action="/i/{{ $invite->code }}" enctype="multipart/form-data" class="mt-6 space-y-3" data-controller="login">
            @csrf
            <label class="flex items-center gap-4">
                <span class="relative shrink-0">
                    <x-ui.avatar :user="null" :size="56" class="text-xl" data-login-target="avatar"/>
                    <span class="absolute -bottom-1 -right-1 flex size-6 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="camera" class="size-3.5"/></span>
                </span>
                <span class="flex-1">
                    <input name="name" required autocomplete="name" class="field-input" placeholder="Имя и фамилия" value="{{ old('name') }}" autofocus data-login-target="name" data-action="input->login#suggest">
                </span>
                <input type="file" name="avatar" accept="image/*" class="sr-only" data-action="change->login#preview">
            </label>
            <input name="login" required autocomplete="username" autocapitalize="none" spellcheck="false" class="field-input" placeholder="Логин, например ivan.petrov" value="{{ old('login') }}" data-login-target="login" data-action="input->login#touched">
            <input name="password" type="password" required autocomplete="new-password" class="field-input" placeholder="Пароль, от восьми знаков">
            @if ($invite->allows('phone'))
                <input name="phone" type="tel" required inputmode="tel" autocomplete="tel" class="field-input" placeholder="Телефон" value="{{ old('phone') }}">
            @endif
            @if ($invite->allows('email'))
                <input name="email" type="email" required inputmode="email" autocomplete="email" class="field-input" placeholder="Почта" value="{{ old('email') }}">
            @endif
            @foreach (['name', 'login', 'password', 'phone', 'email', 'avatar', 'consent'] as $field)
                @error($field)<p class="text-sm text-danger">{{ $message }}</p>@enderror
            @endforeach
            <label class="flex items-start gap-2.5 px-1 pt-1 text-sm text-ink-muted">
                <span class="check mt-0.5"><input type="checkbox" name="consent" value="1" required></span>
                <span>Даю <a href="/soglasie" class="text-accent-text hover:underline">согласие на обработку персональных данных</a> и принимаю <a href="/soglashenie" class="text-accent-text hover:underline">условия</a></span>
            </label>
            <button type="submit" class="btn btn-accent w-full">Войти в xcar</button>
        </form>
        <p class="mt-5 text-center text-sm text-ink-muted">Уже есть аккаунт? <a href="/vhod" class="text-accent-text hover:underline">Войти</a></p>
        @endif
    @endif
</x-ui.auth>
