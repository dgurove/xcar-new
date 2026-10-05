{{-- Профиль — корень кабинета, как «Настройки» в телефоне: сверху карточка человека (аватар меняется касанием и
     сохраняется сразу, имя, чем входит; «Изменить» — шторка с полями), ниже строки группами — разделы кабинета
     (на телефоне), менеджер покупателя, вход и Telegram, отдельной группой «Выйти». Полей на самом экране нет. --}}
@php
    $menu = \App\Support\Nav::cabinetFor($user, 'phone');
    $badges = \App\Support\Nav::badges($user);
@endphp
<x-ui.cabinet title="Профиль" root>
    <div class="box flex flex-col items-center gap-3 text-center" data-controller="sheet">
        {{-- Аватар — кружок с камерой: выбрал фото — оно сразу встаёт в кружок и сохраняется. --}}
        <form method="post" action="/account" enctype="multipart/form-data" data-controller="avatar">
            @csrf @method('put')
            <button type="button" class="relative block" data-action="avatar#pick" aria-label="Сменить фото">
                <x-ui.avatar :user="$user" :size="88" class="text-3xl" data-avatar-target="circle"/>
                <span class="absolute -bottom-0.5 -right-0.5 flex size-8 items-center justify-center rounded-full bg-accent text-white ring-2 ring-surface"><x-ui.icon name="camera" class="size-4"/></span>
            </button>
            <input type="file" name="avatar" accept="image/*" class="sr-only" tabindex="-1" data-avatar-target="input" data-action="change->avatar#upload">
        </form>
        <div class="min-w-0 max-w-full">
            <h1 class="truncate text-xl sm:text-2xl">{{ $user->name }}</h1>
            @if ($user->phone || $user->login || $user->email)
                {{-- Чем входит — одной серой строкой под именем: факты, а не капсулы (не нажимаются). --}}
                <div class="mt-1 flex flex-wrap justify-center gap-y-0.5">
                    @if ($user->phone)<span class="fact nums">{{ $user->phoneFormatted() }}</span>@endif
                    @if ($user->login)<span class="fact nums">{{ $user->login }}</span>@endif
                    @if ($user->email)<span class="fact">{{ $user->email }}</span>@endif
                </div>
            @endif
        </div>
        @error('avatar')<p class="field-error">{{ $message }}</p>@enderror
        <button type="button" class="btn btn-s btn-quiet" data-action="sheet#open"><x-ui.icon name="edit" class="size-4"/> Изменить</button>
        <x-ui.sheet id="profile-edit" title="Профиль" :open="$errors->hasAny(['first_name', 'last_name', 'email'])">
            <form method="post" action="/account" class="flex flex-col gap-3 text-left">
                @csrf @method('put')
                <x-ui.field name="first_name" label="Имя" :value="$user->first_name" autocomplete="given-name" required/>
                <x-ui.field name="last_name" label="Фамилия" :value="$user->last_name" autocomplete="family-name" required/>
                @if ($user->mayHave('email'))<x-ui.field name="email" label="Почта" type="email" :value="$user->email" autocomplete="email"/>@endif
                <x-ui.button class="mt-2">Сохранить</x-ui.button>
                @if ($user->avatarUrl())
                    <button type="submit" name="remove_avatar" value="1" class="btn btn-ghost text-danger" data-turbo-confirm="Удалить фото?" data-turbo-confirm-label="Удалить">Удалить фото</button>
                @endif
            </form>
        </x-ui.sheet>
    </div>

    {{-- Телефон: разделы кабинета строками — всё, чего нет в таб-баре (на ПК это пилюли и шапка). Переход обычный, не
         заменой: «Назад» на экране раздела вернёт сюда историей. --}}
    @if ($menu)
        <nav class="flex flex-col gap-3 md:hidden" aria-label="Разделы кабинета">
            @foreach ($menu as $group => $links)
                @if ($group !== '')<div class="list-head">{{ $group }}</div>@endif
                <div class="list">
                    @foreach ($links as $link)
                        @php $external = str_starts_with($link['href'], 'http'); @endphp
                        <a href="{{ $link['href'] }}" class="row" @if ($external) data-turbo="false" @endif>
                            <x-ui.row-icon :name="$link['icon']" size="s"/>
                            <span class="min-w-0 flex-1">{{ $link['label'] }}</span>
                            <x-ui.badge :href="$link['href']" :badges="$badges"/>
                            <x-ui.chevron/>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>
    @endif

    @if ($manager)
        {{-- Покупателю — его менеджер; с телефоном вся строка звонит. --}}
        <div class="[&>.list-head]:pt-0">
            <div class="list-head">Ваш менеджер</div>
            <div class="list">
                <x-ui.person-row :user="$manager" :size="44" class="row"/>
            </div>
        </div>
    @endif

    {{-- Вход: пароль, ключи, Telegram, установка — одной группой строк. --}}
    <div class="[&>.list-head]:pt-0">
        <div class="list-head">Вход</div>
        <div class="list">
            <div data-controller="sheet" class="contents">
                <button type="button" class="row w-full text-left" data-action="sheet#open">
                    <x-ui.row-icon name="key" size="s"/>
                    <span class="min-w-0 flex-1">Сменить пароль</span>
                    <x-ui.chevron/>
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
                        <x-ui.row-icon name="faceid" size="s"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $key->alias ?: 'Ключ' }}</span>
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
                    <x-ui.row-icon name="faceid" size="s"/>
                    <span class="min-w-0 flex-1">Добавить это устройство</span>
                    <x-ui.icon name="plus" class="size-5 shrink-0 text-ink-dim"/>
                </button>
            </div>

            <x-telegram.row/>

            <button type="button" class="row w-full text-left" hidden data-pwa-target="install" data-action="pwa#install">
                <x-ui.row-icon name="download" size="s"/>
                <span class="min-w-0 flex-1">Установить приложение</span>
            </button>
        </div>
    </div>

    <form method="post" action="/logout" class="list" data-turbo-confirm="Выйти?">
        @csrf
        <button class="row w-full justify-center text-danger">Выйти</button>
    </form>
</x-ui.cabinet>
