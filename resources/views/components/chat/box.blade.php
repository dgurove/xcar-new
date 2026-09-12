{{-- Лента чата с полем ввода. Сообщения приезжают фрагментами по seq. --}}
@props(['chat', 'messages', 'user', 'tall' => false])
<div data-controller="chat" data-chat-url-value="/chaty/{{ $chat->id }}/soobshcheniya" data-chat-id-value="{{ $chat->id }}" data-chat-last-value="{{ $messages->max('seq') ?? 0 }}" class="flex flex-col {{ $tall ? 'min-h-[60dvh]' : '' }}">
    <div class="flex flex-1 flex-col gap-2 overflow-y-auto {{ $tall ? '' : 'max-h-[50dvh]' }} py-2" data-chat-target="list">
        @include('chat.messages', ['chat' => $chat, 'messages' => $messages, 'user' => $user])
    </div>
    <form class="mt-2 flex items-end gap-2" data-chat-target="form" data-action="submit->chat#send">
        <input type="file" accept="image/*,.pdf,.heic" multiple hidden data-chat-target="files" data-action="change->chat#filesPicked">
        <button type="button" class="btn btn-ghost px-2" data-action="chat#pick" aria-label="Приложить"><x-ui.icon name="clip" class="size-5"/></button>
        <textarea name="text" rows="1" class="field-input !min-h-12 flex-1 resize-none" placeholder="Сообщение" data-chat-target="input" data-action="keydown->chat#keydown" enterkeyhint="send"></textarea>
        <button class="btn btn-accent px-3" aria-label="Отправить"><x-ui.icon name="send" class="size-5"/></button>
    </form>
    <div class="mt-1 text-sm text-ink-muted" data-chat-target="status" hidden></div>
</div>
