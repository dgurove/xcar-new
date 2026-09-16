{{-- Профиль — корень кабинета: карточка человека (аватар сбоку, поля, факты чипами, «Сохранить» сразу
     под полями) и список действий строками, как настройки в приложении. Заголовков и коробок под
     каждую кнопку нет. --}}
<x-ui.cabinet title="Профиль">
    <form method="post" action="/account" enctype="multipart/form-data" class="box form-dense grid grid-cols-[auto_1fr] gap-4 sm:gap-6" data-controller="avatar">
        @csrf @method('put')
        {{-- Аватар — кружок с камерой: нажатие открывает выбор файла, фото сразу встаёт в кружок. --}}
        <div class="flex flex-col items-center gap-2 self-start">
            <button type="button" class="relative shrink-0" data-action="avatar#pick" aria-label="Сменить фото">
                <x-ui.avatar :user="$user" :size="72" class="text-2xl" data-avatar-target="circle"/>
                <span class="absolute -bottom-0.5 -right-0.5 flex size-8 items-center justify-center rounded-full bg-accent text-white ring-2 ring-surface"><x-ui.icon name="camera" class="size-4"/></span>
            </button>
            <input type="file" name="avatar" accept="image/*" class="sr-only" tabindex="-1" data-avatar-target="input" data-action="change->avatar#preview">
            @if ($user->avatarUrl())
                <button type="submit" name="remove_avatar" value="1" class="btn btn-s btn-ghost text-ink-muted" data-turbo-confirm="Удалить фото?">Удалить</button>
            @endif
        </div>
        <div class="flex min-w-0 flex-col gap-3">
            <x-ui.field name="name" label="Имя" :value="$user->name" autocomplete="name" required/>
            @if ($user->mayHave('email'))<x-ui.field name="email" label="Почта" type="email" :value="$user->email" autocomplete="email"/>@endif
            {{-- Чем входит: телефон и логин не меняются — фактами. --}}
            @if ($user->login || $user->phone)
                <div class="flex flex-wrap gap-1.5">
                    @if ($user->login)<span class="tag nums">{{ $user->login }}</span>@endif
                    @if ($user->phone)<span class="tag nums">{{ $user->phoneFormatted() }}</span>@endif
                </div>
            @endif
            @error('avatar')<p class="field-error">{{ $message }}</p>@enderror
            <x-ui.button size="s" class="w-full sm:w-fit">Сохранить</x-ui.button>
        </div>
    </form>

    <div class="flex flex-col gap-2">
        @if ($manager)
            {{-- Покупателю — его менеджер; с телефоном вся строка звонит. --}}
            @php $tag = $manager->phone ? 'a' : 'div'; @endphp
            <{{ $tag }} @if ($manager->phone) href="tel:+{{ $manager->phone }}" @endif class="row">
                <x-ui.avatar :user="$manager" :size="44"/>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{ $manager->name }}</span>
                    <span class="row-sub"><span class="tag">менеджер</span>@if ($manager->phone)<span class="tag nums">{{ $manager->phoneFormatted() }}</span>@endif</span>
                </span>
                @if ($manager->phone)<x-ui.icon name="phone" class="size-5 shrink-0 text-ink-muted"/>@endif
            </{{ $tag }}>
        @endif

        <div data-controller="sheet" class="contents">
            <button type="button" class="row w-full text-left" data-action="sheet#open">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="key" class="size-5"/></span>
                <span class="min-w-0 flex-1 font-medium">Сменить пароль</span>
                <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
            </button>
            <x-ui.sheet id="password" title="Новый пароль" :open="$errors->has('current') || $errors->has('password')">
                <form method="post" action="/account/password" class="flex flex-col gap-3">
                    @csrf
                    <x-ui.field name="current" label="Текущий пароль" type="password" autocomplete="current-password" required/>
                    <x-ui.field name="password" label="Новый пароль" type="password" autocomplete="new-password" required/>
                    <x-ui.field name="password_confirmation" label="Ещё раз" type="password" autocomplete="new-password" required/>
                    <x-ui.button class="mt-2">Сохранить</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>


        @foreach ($passkeys as $key)
            {{-- Строка ключа и есть кнопка удаления: одно действие — сам элемент, с подтверждением. --}}
            <form method="post" action="/passkey/{{ $key->id }}" class="contents"
                data-turbo-confirm="Удалить ключ?" data-turbo-confirm-label="Удалить"
                data-turbo-confirm-text="{{ $key->alias ?: 'Ключ' }}, добавлен {{ $key->created_at->translatedFormat('j M Y') }}. Вход по паролю останется">
                @csrf @method('delete')
                <button class="row w-full text-left">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="faceid" class="size-5"/></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ $key->alias ?: 'Ключ' }}</span>
                        <span class="row-sub">
                            @if ($vault = \App\Users\Passkeys::vault($key))<span class="tag">{{ $vault }}</span>@endif
                            @if ($key->disabled_at)
                                <span class="tag" style="--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d">отключён</span>
                            @elseif ($key->last_used_at)
                                <span class="tag nums">вход {{ \Illuminate\Support\Carbon::parse($key->last_used_at)->translatedFormat('j M') }}</span>
                            @else
                                <span class="tag nums">{{ $key->created_at->translatedFormat('j M') }}</span>
                            @endif
                        </span>
                    </span>
                    <x-ui.icon name="trash" class="size-5 shrink-0 text-ink-muted"/>
                </button>
            </form>
        @endforeach
        <div data-controller="passkey" hidden class="contents">
            <button type="button" class="row w-full text-left" data-action="passkey#register">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="faceid" class="size-5"/></span>
                <span class="min-w-0 flex-1 font-medium">Добавить это устройство</span>
                <x-ui.icon name="plus" class="size-5 shrink-0 text-ink-dim"/>
            </button>
        </div>

        <button type="button" class="row w-full text-left" hidden data-pwa-target="install" data-action="pwa#install">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="download" class="size-5"/></span>
            <span class="min-w-0 flex-1 font-medium">Установить приложение</span>
        </button>

        <form method="post" action="/logout" class="contents" data-turbo-confirm="Выйти?">
            @csrf
            <button class="row w-full text-left text-danger">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-danger-soft"><x-ui.icon name="exit" class="size-5"/></span>
                <span class="min-w-0 flex-1 font-medium">Выйти</span>
            </button>
        </form>
    </div>
</x-ui.cabinet>
