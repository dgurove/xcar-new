{{-- Чаты как в мессенджере: панель строк (собеседник, ТС, последнее сообщение, бейдж); на широком
     экране справа открытый чат (фрейм chat-screen, split_controller ведёт строки в него), на
     телефоне — либо список, либо экран (current). current = 'new' — экран «написать» по ТС без чата. --}}
@php
    $me = auth()->user();
    $current ??= null;
    $chat ??= null;
    $offer ??= null;
    $other = $chat ? ($chat->isCounterpart($me) ? $chat->user : $chat->manager) : ($me->isBuyer() ? $me->manager : null);
    $name = $chat ? $chat->counterpartName($me) : ($other?->shortName() ?? 'XCar');
@endphp
<x-ui.cabinet :title="$current ? $name : 'Чаты'">
    <div class="chat-split {{ $current ? 'has-current' : '' }}" data-controller="split">
        <div class="chat-rows">
            @if ($chats->isEmpty())
                <x-ui.empty href="/contacts" link="Написать нам">Чатов пока нет</x-ui.empty>
            @else
                <div class="chat-rows-list">
                    @foreach ($chats as $c)
                        <x-chat.row :chat="$c" :me="$me" :href="'/account/chats/'.$c->id" :current="$c->id === $current"/>
                    @endforeach
                </div>
                {{ $chats->links() }}
            @endif
        </div>
        <turbo-frame id="chat-screen" class="chat-pane" target="_top">
            @if ($current)
                <x-chat.screen :chat="$chat" :offer="$offer" :messages="$messages" :user="$me" :name="$name" :other="$other" :first-unread="$firstUnread" :more="$more"/>
            @else
                <div class="chat-none"><x-ui.icon name="chat" class="size-10 text-ink-dim"/>Выберите чат</div>
            @endif
        </turbo-frame>
    </div>
</x-ui.cabinet>
