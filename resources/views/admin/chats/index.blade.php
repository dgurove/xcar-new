{{-- Чаты в CRM: панель «список | переписка», лупа — над строками (x-chat.list-head), пилюль нет. Чат площадки — с
     полем ответа; переписка покупателя с менеджером (открывают из карточки пользователя) — только читается (readonly:
     поля нет, счётчики не трогаются), в баре — покупатель и чип его менеджера. --}}
@php
    $current ??= null;
    $chat ??= null;
    $me = auth()->user();
    $qs = request()->getQueryString() ? '?'.request()->getQueryString() : '';
    // Тот же экран и в XCAR Chat (20.11.2026): там корень — `/chats`, а карточки людей CRM нет.
    $base = \App\Chats\Chat::base();
    $crm = \App\Support\Surface::inCrm();
@endphp
<x-ui.shell :title="$chat ? $chat->displayName() : 'Чаты'" :heading="false" :back="$chat ? ['Чаты', $base.$qs] : null" :back-row="false">
    {{-- Заголовок раздела (16.11.2026: «Чаты» — свой таб таб-бара CRM); открыт чат на телефоне — прячется. --}}
    <div class="chat-crm-top">
        {{-- XCAR Chat без шапки: профиль, тема и выход — аватаром справа (`partner.me`). Ряд — внутри: у `.chat-crm-top` свой
             `display: none` в открытом чате на телефоне, утилита `flex` на нём его перебила бы. --}}
        <div class="flex items-center justify-between gap-3">
            <h1 class="head-title text-xl sm:text-2xl">Чаты</h1>
            @if (\App\Support\Surface::current() === \App\Support\Surface::Chat)
                @include('partner.me')
            @endif
        </div>
    </div>
    <div class="chat-split chat-split-crm mt-4 {{ $current ? 'has-current' : '' }}" data-controller="split">
        <div class="chat-rows" id="chat-rows">
            <x-chat.list-head search="Имя, телефон, ТС, номер" :url="$base" :count="$chats->total()" :q="$q"/>
            <div class="chat-rows-body" id="chat-rows-body">
                @if ($chats->isEmpty())
                    <x-ui.empty>{{ $q !== '' ? 'Ничего не нашли' : 'Чатов нет' }}</x-ui.empty>
                @else
                    <div class="chat-rows-list" data-fresh-on-back>
                        @foreach ($chats as $c)
                            <x-chat.row :chat="$c" :me="$me" :href="$base.'/'.$c->id.$qs" :current="$c->id === $current" staff/>
                        @endforeach
                    </div>
                    <x-ui.pager :of="$chats"/>
                @endif
            </div>
        </div>
        <turbo-frame id="chat-screen" class="chat-pane" target="_top">
            @if ($chat)
                <x-chat.screen :chat="$chat" :messages="$messages" :user="$user" :name="$chat->displayName()" :other="$chat->user" :back="$base.$qs" :first-unread="$firstUnread" :more="$more" :readonly="$chat->isBuyerChat()" :link="$crm && $chat->user_id ? '/settings/users/'.$chat->user_id.'?chat='.$chat->id : null"/>
            @else
                <x-chat.empty/>
            @endif
        </turbo-frame>
    </div>
</x-ui.shell>
