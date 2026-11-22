{{-- Чаты в CRM: панель «список | переписка», лупа — над строками (x-chat.list-head), пилюль нет. Чат площадки — с
     полем ответа; переписка покупателя с менеджером (открывают из карточки пользователя) — только читается (readonly:
     поля нет, счётчики не трогаются), в баре — покупатель и чип его менеджера. --}}
@php
    $current ??= null;
    $chat ??= null;
    $with ??= null;
    $people ??= collect();
    $me = auth()->user();
    // Своя личка (22.11.2026, `Chats\Direct`) — собеседник читающего, с полем ответа; чужая переписка — участник и чип второй стороны.
    $party = $chat?->hasParty($me) && $chat->manager_id;
    $other = $chat ? ($party ? $chat->otherFor($me) : $chat->user) : $with;
    $name = $chat ? ($party ? $chat->counterpartName($me) : $chat->displayName()) : $with?->name;
    $qs = request()->getQueryString() ? '?'.request()->getQueryString() : '';
    // Тот же экран и в XCAR Chat (20.11.2026): там корень — `/chats`, а карточки людей CRM нет.
    $base = \App\Chats\Chat::base();
    $crm = \App\Support\Surface::inCrm();
@endphp
<x-ui.shell :title="$name ?? 'Чаты'" :heading="false" :back="$current ? ['Чаты', $base.$qs] : null" :back-row="false">
    <x-chat.list-top/>
    <div class="chat-split chat-split-crm mt-4 {{ $current ? 'has-current' : '' }}" data-controller="split">
        <div class="chat-rows" id="chat-rows">
            <x-chat.list-head search="Имя, телефон, ТС, номер" :url="$base" :count="$chats->total()" :q="$q" :people="\App\Chats\Direct::partners($me)->isNotEmpty()"/>
            <div class="chat-rows-body" id="chat-rows-body">
                @if ($chats->isEmpty() && $people->isEmpty())
                    <x-ui.empty>{{ $q !== '' ? 'Ничего не нашли' : 'Чатов нет' }}</x-ui.empty>
                @else
                    <div class="chat-rows-list" data-fresh-on-back>
                        <x-chat.people :people="$people" :me="$me" :base="$base" :qs="$qs" :current="$current" staff/>
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
                <x-chat.screen :chat="$chat" :messages="$messages" :user="$user" :name="$name" :other="$other" :back="$base.$qs" :first-unread="$firstUnread" :more="$more" :readonly="! $chat->canPost($user)" :link="$crm && $other ? '/settings/users/'.$other->id.'?chat='.$chat->id : null"/>
            @elseif ($with)
                {{-- Пустая личка: первое сообщение её заведёт (`POST /chats/with/{user}`), приветствия площадки нет. --}}
                <x-chat.screen :chat="null" :messages="$messages" :user="$user" :name="$name" :other="$with" :back="$base.$qs" :open="'/chats/with/'.$with->id" :greet="false"/>
            @else
                <x-chat.empty/>
            @endif
        </turbo-frame>
    </div>
</x-ui.shell>
