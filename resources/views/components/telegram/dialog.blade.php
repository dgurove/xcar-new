{{-- Переписка бота с человеком: шапка (кто, @username, роль; касание — карточка человека в CRM), лента и поле.
     Лента и поле — те же, что у чатов CRM (`chat_controller`, классы .chat/.msg): бот справа — мы пишем от его
     имени, человек слева. События хаба свои (live:tg-chat), чтобы номера чатов не путались с чатами сайта.
     Меню пузыря: ответить, скопировать; изменить и удалить — только написанное из CRM. --}}
@props(['chat', 'messages', 'more' => false, 'back' => '/settings/telegram'])
@php
    $sub = collect([$chat->username ? '@'.$chat->username : null, $chat->roleLabel(), $chat->left_at ? 'остановил бота' : null])->filter()->first() ?? 'Telegram';
    $url = '/settings/telegram/'.$chat->id.'/messages';
@endphp
<div class="chat-page">
    <div class="chat-head">
        <a href="{{ $back }}" class="chat-back header-btn" aria-label="Назад" data-controller="back" data-action="back#go" data-turbo-action="replace"><x-ui.icon name="chevron-left" class="size-5"/></a>
        @if ($chat->user_id)<a href="/settings/users/{{ $chat->user_id }}" class="chat-who" data-turbo-action="advance">@else<div class="chat-who">@endif
            <x-telegram.avatar :chat="$chat" :size="36"/>
            <div class="min-w-0 flex-1">
                <div class="truncate font-medium leading-tight">{{ $chat->displayName() }}</div>
                <div class="truncate text-sm leading-tight {{ $chat->left_at ? 'text-danger' : 'text-ink-muted' }}" data-chat-status>{{ $sub }}</div>
            </div>
        @if ($chat->user_id)</a>@else</div>@endif
        @if ($chat->username)<a href="https://t.me/{{ $chat->username }}" target="_blank" rel="noopener" class="btn btn-ghost btn-round" aria-label="Открыть в Telegram"><x-telegram.logo plain/></a>@endif
    </div>
    <div data-controller="chat" data-chat-url-value="{{ $url }}" data-chat-id-value="{{ $chat->id }}" data-chat-last-value="{{ $messages->max('id') ?? 0 }}" data-chat-readonly-value="false" data-action="live:tg-chat@document->chat#live live:tg-chat-edit@document->chat#edited" class="chat chat-screen">
        <div class="chat-list" data-chat-target="list" data-action="scroll->chat#scrolled dragover->chat#over:prevent drop->chat#drop:prevent">
            @if ($messages->isEmpty())
                <div class="msg is-system"><div class="msg-system">Переписка появится здесь с первым сообщением</div></div>
            @else
                @include('admin.telegram.messages', ['chat' => $chat, 'messages' => $messages, 'more' => $more])
            @endif
        </div>
        <div class="chat-bar">
            <div class="chat-state" data-chat-target="state" hidden>
                <x-ui.icon name="reply" class="size-4 shrink-0 text-accent-text" data-chat-target="stateIcon"/>
                <div class="min-w-0 flex-1"><div class="truncate text-xs text-accent-text" data-chat-target="stateName"></div><div class="truncate text-sm" data-chat-target="stateText"></div></div>
                <button type="button" class="btn btn-ghost btn-s px-2" data-action="chat#cancel" aria-label="Отменить"><x-ui.icon name="x" class="size-4"/></button>
            </div>
            <div class="chat-previews" data-chat-target="previews" hidden></div>
            <form method="post" class="chat-form" data-chat-target="form" data-action="submit->chat#send" data-controller="draft" data-draft-key-value="tg:{{ $chat->id }}">
                <input type="file" multiple hidden data-chat-target="files" data-action="change->chat#filesPicked">
                <button type="button" class="btn btn-ghost btn-round" data-action="chat#pick" aria-label="Приложить"><x-ui.icon name="clip" class="size-5"/></button>
                <div class="chat-input"><textarea name="text" rows="1" placeholder="Сообщение от бота" data-chat-target="input" data-action="keydown->chat#keydown input->chat#typed change->chat#typed paste->chat#paste" enterkeyhint="enter"></textarea></div>
                <button class="chat-send is-empty" aria-label="Отправить" data-chat-target="submit"><x-ui.icon name="send" class="size-5"/></button>
            </form>
        </div>
        <div class="menu" popover data-chat-target="menu" role="menu">
            <button type="button" class="menu-item w-full" role="menuitem" data-action="chat#reply"><x-ui.icon name="reply" class="size-5"/>Ответить</button>
            <button type="button" class="menu-item w-full" role="menuitem" data-action="chat#copy" data-chat-target="copyItem"><x-ui.icon name="copy" class="size-5"/>Скопировать</button>
            <button type="button" class="menu-item w-full" role="menuitem" data-action="chat#startEdit" data-chat-target="ownItem editItem"><x-ui.icon name="edit" class="size-5"/>Изменить</button>
            <button type="button" class="menu-item w-full text-danger" role="menuitem" data-action="chat#remove" data-chat-target="ownItem removeItem"><x-ui.icon name="trash" class="size-5"/>Удалить</button>
        </div>
    </div>
</div>
