<x-ui.cabinet title="Профиль" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Профиль']]">
    <form method="post" action="/lk" enctype="multipart/form-data" class="box">
        @csrf @method('put')
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.field name="name" label="Имя" :value="$user->name" autocomplete="name" required/>
            <x-ui.field name="email" label="Почта" type="email" :value="$user->email" autocomplete="email"/>
        </div>
        <div class="mt-4">
            {{-- Телефон — ключ входа, менять его здесь нельзя. --}}
            <span class="block text-sm text-ink-dim">Телефон</span>
            <p class="nums mt-1.5 font-normal">{{ $user->phoneFormatted() }}</p>
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

    <section class="box mt-4">
        <h2 class="text-xl">Вход по Face ID</h2>
        <div class="mt-4 flex flex-col gap-2">
            @foreach ($passkeys as $key)
                <form method="post" action="/passkey/{{ $key->id }}" class="flex items-center gap-3 rounded-(--radius-l) bg-surface-2 px-4 py-3">
                    @csrf @method('delete')
                    <x-ui.icon name="key" class="size-5 text-ink-muted"/>
                    <span class="flex-1">{{ $key->alias ?: 'Ключ' }}<span class="nums ml-2 text-sm font-normal text-ink-dim">{{ $key->created_at->translatedFormat('j M Y') }}</span></span>
                    <button class="sheet-close" aria-label="Удалить"><x-ui.icon name="trash" class="size-5"/></button>
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
