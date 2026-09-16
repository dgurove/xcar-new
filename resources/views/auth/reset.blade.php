<x-ui.auth title="Новый пароль">
    <form method="post" action="/password/new" class="mt-6 space-y-3">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input name="email" type="email" required autocomplete="email" class="field-input" placeholder="Почта" value="{{ old('email', $email) }}">
        <input name="password" type="password" required autocomplete="new-password" class="field-input" placeholder="Новый пароль" autofocus>
        <input name="password_confirmation" type="password" required autocomplete="new-password" class="field-input" placeholder="Ещё раз">
        @foreach (['email', 'password'] as $field)
            @error($field)<p class="text-sm text-danger">{{ $message }}</p>@enderror
        @endforeach
        <button type="submit" class="btn btn-accent w-full">Сохранить</button>
    </form>
</x-ui.auth>
