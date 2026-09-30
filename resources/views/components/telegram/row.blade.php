{{-- Строка Telegram в профиле, как в «Настройках» iPhone: синяя плитка, справа «Подключить» или @имя.
     Привязан — шторка: уведомления тумблером, пробное сообщение, отключить. Не привязан — открывает шторку подключения. --}}
@php $user = auth()->user(); @endphp
@if ($user->telegram_chat_id)
    <div data-controller="sheet" class="contents">
        <button type="button" class="row w-full text-left" data-action="sheet#open">
            <span class="profile-icon tg-tile"><x-telegram.logo plain class="size-[18px]"/></span>
            <span class="min-w-0 flex-1">Telegram</span>
            <span class="truncate text-ink-muted">{{ $user->telegram_username ? '@'.$user->telegram_username : 'подключён' }}</span>
            <x-ui.chevron/>
        </button>
        <x-ui.sheet id="telegram-account" title="Telegram">
            <div class="flex flex-col gap-5">
                <div class="flex flex-col items-center gap-1 text-center">
                    <x-telegram.logo class="mb-2 size-16"/>
                    <span class="text-lg">{{ $user->telegram_username ? '@'.$user->telegram_username : $user->name }}</span>
                    @if ($user->telegram_linked_at)<span class="text-sm text-ink-muted">подключён {{ $user->telegram_linked_at->translatedFormat('j M') }}</span>@endif
                </div>
                <div class="list">
                    <form method="post" action="/account/telegram" class="contents" data-controller="autosubmit">
                        @csrf @method('put')
                        <label class="row row-switch">
                            <span class="min-w-0 flex-1">Уведомления</span>
                            <input type="checkbox" name="on" value="1" class="switch" @checked($user->wantsTelegram()) data-action="change->autosubmit#submit">
                        </label>
                    </form>
                    <form method="post" action="/account/telegram/test" class="contents">
                        @csrf
                        <button class="row w-full text-left"><span class="min-w-0 flex-1">Отправить пробное</span><x-ui.icon name="send" class="size-5 shrink-0 text-ink-dim"/></button>
                    </form>
                </div>
                <form method="post" action="/account/telegram" class="list" data-turbo-confirm="Отключить Telegram?" data-turbo-confirm-label="Отключить" data-turbo-confirm-text="Уведомления и вход через Telegram перестанут работать">
                    @csrf @method('delete')
                    <button class="row w-full justify-center text-danger">Отключить Telegram</button>
                </form>
            </div>
        </x-ui.sheet>
    </div>
@elseif ($user->canLinkTelegram())
    <button type="button" class="row w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="telegram:open">
        <span class="profile-icon tg-tile"><x-telegram.logo plain class="size-[18px]"/></span>
        <span class="min-w-0 flex-1">Telegram</span>
        <span class="font-medium" style="color: var(--color-telegram)">Подключить</span>
        <x-ui.chevron/>
    </button>
@endif
