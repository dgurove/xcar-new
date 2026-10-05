{{-- Настройки → «Бот Telegram» (только админам): все чаты бота строками, справа — переписка как в Telegram.
     Раскладка — как «Чаты» в кабинете (chat-split, split_controller, фрейм chat-screen); счётчиков и
     непрочитанных нет: раздел, чтобы посмотреть, что бот пишет и что ему отвечают. --}}
@php
    $current ??= null;
    $chat ??= null;
    $qs = request()->getQueryString() ? '?'.request()->getQueryString() : '';
@endphp
<x-ui.cabinet :title="$chat ? $chat->displayName() : 'Бот Telegram'">
    <div class="chat-split {{ $current ? 'has-current' : '' }}" data-controller="split">
        <div class="chat-rows" id="chat-rows">
            <x-chat.list-head search="Имя, @username, текст" url="/settings/telegram" :count="$chats->total()" :q="$q"/>
            <div class="chat-rows-body" id="chat-rows-body">
                @if ($chats->isEmpty())
                    <x-ui.empty>{{ $q !== '' ? 'Ничего не нашли' : 'Чатов нет' }}</x-ui.empty>
                @else
                    <div class="chat-rows-list">
                        @foreach ($chats as $c)
                            <x-telegram.chat-row :chat="$c" :href="'/settings/telegram/'.$c->id.$qs" :current="$c->id === $current"/>
                        @endforeach
                    </div>
                    <x-ui.pager :of="$chats" :sizes="[]"/>
                @endif
            </div>
        </div>
        <turbo-frame id="chat-screen" class="chat-pane" target="_top">
            @if ($chat)
                <x-telegram.dialog :chat="$chat" :messages="$messages" :more="$more" :back="'/settings/telegram'.$qs"/>
            @else
                <div class="chat-none"><x-ui.icon name="chat" class="size-10 text-ink-dim"/>Выберите чат</div>
            @endif
        </turbo-frame>
    </div>
</x-ui.cabinet>
