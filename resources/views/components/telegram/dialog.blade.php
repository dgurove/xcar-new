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
        <x-chat.back :href="$back"/>
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
        <x-chat.bar :draft="'tg:'.$chat->id" placeholder="Сообщение от бота" accept=""/>
    </div>
</div>
