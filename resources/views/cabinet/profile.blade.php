<x-ui.cabinet title="Профиль" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Профиль']]">
    <form method="post" action="/lk" enctype="multipart/form-data" class="box">
        @csrf @method('put')
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.field name="name" label="Имя" :value="$user->name" autocomplete="name" required/>
            @if ($user->mayHave('email'))<x-ui.field name="email" label="Почта" type="email" :value="$user->email" autocomplete="email"/>@endif
        </div>
        {{-- Чем входит: телефон и логин не меняются, у покупателя контакты — только разрешённые менеджером. --}}
        <div class="mt-4 flex flex-wrap gap-6">
            @if ($user->login)
                <div><span class="block text-sm text-ink-dim">Логин</span><p class="nums mt-1.5 font-normal">{{ $user->login }}</p></div>
            @endif
            @if ($user->phone)
                <div><span class="block text-sm text-ink-dim">Телефон</span><p class="nums mt-1.5 font-normal">{{ $user->phoneFormatted() }}</p></div>
            @endif
        </div>
        <div class="mt-4">
            <span class="block text-sm text-ink-dim">Аватар</span>
            <div class="mt-1.5 flex items-center gap-4">
                <x-ui.avatar :user="$user" :size="64" class="text-xl"/>
                <div class="flex flex-col gap-2">
                    <input type="file" name="avatar" accept="image/*" class="block w-full text-sm text-ink-dim file:mr-3 file:cursor-pointer file:rounded-full file:border-0 file:bg-surface-3 file:px-4 file:py-2 file:text-sm file:text-ink hover:file:bg-line">
                    @if ($user->avatarUrl())<x-ui.check name="remove_avatar" value="1">Удалить аватар</x-ui.check>@endif
                </div>
            </div>
            @error('avatar')<p class="field-error mt-1">{{ $message }}</p>@enderror
        </div>
        <div class="mt-6 flex items-center justify-between gap-3">
            <x-ui.pill tone="plain">{{ $user->role->label() }}</x-ui.pill>
            <x-ui.button>Сохранить</x-ui.button>
        </div>
    </form>

    <section class="box mt-4" data-controller="sheet">
        <h2 class="text-xl">Пароль</h2>
        <x-ui.button type="button" variant="secondary" class="mt-4" data-action="sheet#open"><x-ui.icon name="key" class="size-5"/> Сменить пароль</x-ui.button>
        <x-ui.sheet id="password" title="Новый пароль" :open="$errors->has('current') || $errors->has('password')">
            <form method="post" action="/lk/parol" class="flex flex-col gap-3">
                @csrf
                <x-ui.field name="current" label="Текущий пароль" type="password" autocomplete="current-password" required/>
                <x-ui.field name="password" label="Новый пароль" type="password" autocomplete="new-password" required/>
                <x-ui.field name="password_confirmation" label="Ещё раз" type="password" autocomplete="new-password" required/>
                <x-ui.button class="mt-2">Сохранить</x-ui.button>
            </form>
        </x-ui.sheet>
    </section>

    <section class="box mt-4">
        <h2 class="text-xl">Вход по ключу</h2>
        <div class="mt-4 flex flex-col gap-2">
            @foreach ($passkeys as $key)
                {{-- Строка ключа и есть кнопка удаления: одно действие — сам элемент, с подтверждением. --}}
                <form method="post" action="/passkey/{{ $key->id }}"
                    data-turbo-confirm="Удалить ключ?" data-turbo-confirm-label="Удалить"
                    data-turbo-confirm-text="{{ $key->alias ?: 'Ключ' }}, добавлен {{ $key->created_at->translatedFormat('j M Y') }}. Вход по паролю останется">
                    @csrf @method('delete')
                    <button class="row w-full bg-surface-2 text-left">
                        <x-ui.icon name="key" class="size-5 shrink-0 text-ink-muted"/>
                        <span class="min-w-0 flex-1">{{ $key->alias ?: 'Ключ' }}</span>
                        <span class="flex shrink-0 flex-wrap justify-end gap-1.5">
                            @if ($vault = \App\Users\Passkeys::vault($key))<span class="tag">{{ $vault }}</span>@endif
                            @if ($key->disabled_at)
                                <span class="tag" style="--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d">отключён</span>
                            @elseif ($key->last_used_at)
                                <span class="tag">вход {{ \Illuminate\Support\Carbon::parse($key->last_used_at)->translatedFormat('j M') }}</span>
                            @else
                                <span class="tag">{{ $key->created_at->translatedFormat('j M') }}</span>
                            @endif
                        </span>
                        <x-ui.icon name="trash" class="size-5 shrink-0 text-ink-muted"/>
                    </button>
                </form>
            @endforeach
        </div>
        <div data-controller="passkey" hidden class="{{ $passkeys->isNotEmpty() ? 'mt-3' : '' }}">
            <x-ui.button type="button" variant="secondary" block data-action="passkey#register"><x-ui.icon name="faceid" class="size-5"/> Добавить это устройство</x-ui.button>
        </div>
    </section>

    <form method="post" action="/vyhod" class="mt-6">
        @csrf
        <x-ui.button variant="secondary"><x-ui.icon name="exit" class="size-5"/> Выйти</x-ui.button>
    </form>
</x-ui.cabinet>
