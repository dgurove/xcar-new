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
        <x-chat.bar :draft="'chat:'.($chat?->id ?? request()->path())"/>
    @endunless
</div>
