{{-- Лента чата с полем ввода. Сообщения приезжают фрагментами по seq; вверху подгружаются старые.
     Чата может ещё не быть: тогда приветствие площадки статичное (покупателю, чей чат — с его
     менеджером, лента просто пуста), а первое сообщение уходит на open и заводит чат.
     screen — экран мессенджера во всю высоту (кабинет, CRM); readonly — только читать (сотрудник
     в чужом чате). Над полем — чип ответа или правки, ряд превью выбранных фото; меню пузыря —
     один popover на ленту. --}}
@props(['chat' => null, 'messages', 'user', 'tall' => false, 'screen' => false, 'open' => null, 'readonly' => false, 'firstUnread' => 0, 'more' => false])
@php $readonly = $readonly || ($chat && $user && ! $chat->canPost($user)); @endphp
<div data-controller="chat" data-chat-url-value="{{ $chat ? '/chats/'.$chat->id.'/messages' : '' }}" data-chat-open-value="{{ $open ?? '' }}" data-chat-id-value="{{ $chat?->id ?? 0 }}" data-chat-last-value="{{ $messages->max('seq') ?? 0 }}" data-chat-readonly-value="{{ $readonly ? 'true' : 'false' }}" data-action="live:chat@document->chat#live live:chat-edit@document->chat#edited live:chat-read@document->chat#read live:chat-typing@document->chat#typing" {{ $attributes->merge(['class' => 'chat '.($screen ? 'chat-screen' : ($tall ? 'chat-tall' : ''))]) }}>
    <div class="chat-list" data-chat-target="list" data-action="scroll->chat#scrolled dragover->chat#over:prevent drop->chat#drop:prevent">
        @if ($chat)
            @include('chat.messages', ['chat' => $chat, 'messages' => $messages, 'user' => $user, 'firstUnread' => $firstUnread, 'more' => $more, 'readonly' => $readonly])
        @elseif (!$user?->isBuyer())
            <div class="msg is-system"><div class="msg-system">{{ \App\Chats\Actions\OpenChat::GREETING }}</div></div>
        @endif
    </div>
    @unless ($readonly)
        <div class="chat-state" data-chat-target="state" hidden>
            <x-ui.icon name="reply" class="size-4 shrink-0 text-accent-text" data-chat-target="stateIcon"/>
            <div class="min-w-0 flex-1"><div class="truncate text-xs text-accent-text" data-chat-target="stateName"></div><div class="truncate text-sm" data-chat-target="stateText"></div></div>
            <button type="button" class="btn btn-ghost btn-s px-2" data-action="chat#cancel" aria-label="Отменить"><x-ui.icon name="x" class="size-4"/></button>
        </div>
        <div class="chat-previews" data-chat-target="previews" hidden></div>
        <form method="post" class="chat-form" data-chat-target="form" data-action="submit->chat#send" data-controller="draft" data-draft-key-value="chat:{{ $chat?->id ?? request()->path() }}">
            <input type="file" accept="image/*,.pdf,.heic" multiple hidden data-chat-target="files" data-action="change->chat#filesPicked">
            <button type="button" class="btn btn-ghost px-2" data-action="chat#pick" aria-label="Приложить"><x-ui.icon name="clip" class="size-5"/></button>
            <textarea name="text" rows="1" class="field-input !min-h-12 flex-1 resize-none" placeholder="Сообщение" data-chat-target="input" data-action="keydown->chat#keydown input->chat#typed paste->chat#paste" enterkeyhint="enter"></textarea>
            <button class="btn btn-accent px-3" aria-label="Отправить" data-chat-target="submit"><x-ui.icon name="send" class="size-5"/></button>
        </form>
        <div class="menu" popover data-chat-target="menu" role="menu">
            <button type="button" class="menu-item w-full" role="menuitem" data-action="chat#reply"><x-ui.icon name="reply" class="size-5"/>Ответить</button>
            <button type="button" class="menu-item w-full" role="menuitem" data-action="chat#copy" data-chat-target="copyItem"><x-ui.icon name="copy" class="size-5"/>Скопировать</button>
            <button type="button" class="menu-item w-full" role="menuitem" data-action="chat#startEdit" data-chat-target="ownItem editItem"><x-ui.icon name="edit" class="size-5"/>Изменить</button>
            <button type="button" class="menu-item w-full text-danger" role="menuitem" data-action="chat#remove" data-chat-target="ownItem removeItem"><x-ui.icon name="trash" class="size-5"/>Удалить</button>
        </div>
    @endunless
</div>
