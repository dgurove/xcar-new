<x-ui.auth title="Восстановление пароля">
    @if (session('status'))
        <x-ui.flash class="mt-6">{{ session('status') }}</x-ui.flash>
    @else
        <form method="post" action="/parol" class="mt-6 space-y-3">
            @csrf
            <input name="email" type="email" required inputmode="email" autocomplete="email" class="field-input" placeholder="Почта" value="{{ old('email') }}" autofocus>
            @error('email')<p class="text-sm text-danger">{{ $message }}</p>@enderror
            <button type="submit" class="btn btn-accent w-full">Прислать ссылку</button>
        </form>
    @endif
    <a href="/vhod" class="mt-5 inline-block text-sm text-ink-muted hover:text-accent-text">Вернуться ко входу</a>
</x-ui.auth>
