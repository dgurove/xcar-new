<x-ui.shell title="Кабинет">
    <div class="flex flex-col gap-4">
        <x-ui.card>
            <form method="post" action="/lk" class="flex flex-col gap-4">
                @csrf @method('put')
                <x-ui.field name="name" label="Имя" :value="$user->name" autocomplete="name" required/>
                <x-ui.field name="phone" label="Телефон" :value="$user->phoneFormatted()" disabled/>
                <x-ui.field name="email" label="Почта" type="email" :value="$user->email" autocomplete="email"/>
                <div class="flex items-center justify-between">
                    <span class="chip">{{ $user->role->label() }}</span>
                    <x-ui.button size="sm">Сохранить</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card title="Вход по Face ID">
            @foreach ($passkeys as $key)
                <form method="post" action="/passkey/{{ $key->id }}" class="flex items-center gap-3 py-2">
                    @csrf @method('delete')
                    <x-ui.icon name="key" class="size-5 text-ink-muted"/>
                    <span class="flex-1">{{ $key->alias ?: 'Ключ' }}<span class="ml-2 text-sm text-ink-muted">{{ $key->created_at->translatedFormat('j M Y') }}</span></span>
                    <button class="btn btn-ghost btn-sm px-2 text-ink-muted" aria-label="Удалить"><x-ui.icon name="trash" class="size-5"/></button>
                </form>
            @endforeach
            <div data-controller="passkey" hidden class="{{ $passkeys->isNotEmpty() ? 'mt-3' : '' }}">
                <x-ui.button type="button" variant="secondary" block data-action="passkey#register">
                    <x-ui.icon name="faceid" class="size-5"/> Добавить это устройство
                </x-ui.button>
            </div>
        </x-ui.card>

        <form method="post" action="/vyhod">
            @csrf
            <x-ui.button variant="ghost" block><x-ui.icon name="logout" class="size-5"/> Выйти</x-ui.button>
        </form>
    </div>
</x-ui.shell>
