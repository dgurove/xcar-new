{{-- Чаты как в мессенджере: панель строк (собеседник, ТС, последнее сообщение, бейдж); на широком
     экране справа открытый чат (фрейм chat-screen, split_controller ведёт строки в него), на
     телефоне — либо список, либо экран (current). current = 'new' — экран «написать» по ТС без чата. --}}
@php
    $me = auth()->user();
    $current ??= null;
    $chat ??= null;
    $offer ??= null;
    $other = $chat ? ($chat->isCounterpart($me) ? $chat->user : $chat->manager) : ($offer && $me->isBuyer() ? $me->manager : null);
    $name = $chat ? $chat->counterpartName($me) : ($other?->shortName() ?? \App\Chats\Chat::PLATFORM);
    // Шапка ведёт к собеседнику: менеджеру — страница покупателя с возвратом в чат; покупателю и всем,
    // кто пишет площадке, — шторка-контакт; статус там по рабочему времени, а не по seen_at.
    $mine = $chat && $chat->isCounterpart($me);
    // Покупатель мог уйти к другому менеджеру — чат остался, а его страницы у меня больше нет.
    $link = $mine && $other->manager_id === $me->id ? '/account/buyers/'.$other->id.'?chat='.$chat->id : null;
    $status = $mine ? false : \App\Chats\Hours::presence(feminine: $other === null);
    $others = $mine || ! $other ? collect() : $chats->filter(fn ($c) => $c->manager_id === $other->id && $c->id !== $chat?->id);
    // «Администрация XCar» в списке есть всегда: обращения ещё нет — строка ведёт на пустой экран.
    $support = !$me->isStaff() && $chats->doesntContain(fn ($c) => $c->isEnquiry());
@endphp
<x-ui.cabinet :title="$current ? $name : 'Чаты'">
    <div class="chat-split {{ $current ? 'has-current' : '' }}" data-controller="split">
        <div class="chat-rows">
            <div class="chat-rows-list">
                @foreach ($chats as $c)
                    <x-chat.row :chat="$c" :me="$me" :href="'/account/chats/'.$c->id" :current="$c->id === $current"/>
                @endforeach
                @if ($support)
                    <a href="/account/chats/support" class="chat-row" @if ($current === 'support') aria-current="true" @endif data-turbo-action="advance">
                        <x-chat.avatar :size="44"/>
                        <div class="min-w-0 flex-1 truncate">{{ \App\Chats\Chat::PLATFORM }}</div>
                    </a>
                @endif
            </div>
            <x-ui.pager :of="$chats" :sizes="[]"/>
        </div>
        <turbo-frame id="chat-screen" class="chat-pane" target="_top">
            @if ($current)
                <x-chat.screen :chat="$chat" :offer="$offer" :messages="$messages" :user="$me" :name="$name" :other="$other" :open="$current === 'support' ? '/chats/support' : null" :first-unread="$firstUnread" :more="$more" :link="$link" :sheet="! $mine" :status="$status" :chats="$others"/>
            @else
                <div class="chat-none"><x-ui.icon name="chat" class="size-10 text-ink-dim"/>Выберите чат</div>
            @endif
        </turbo-frame>
    </div>
</x-ui.cabinet>
