{{-- Список чатов и открытый чат — кусок экрана раздела «Чаты» сайта (`cabinet.chats`); сетка — как у «Чатов» CRM. --}}
    <div class="chat-split chat-split-crm mt-4 {{ $current ? 'has-current' : '' }}" data-controller="split">
        <div class="chat-rows" id="chat-rows">
            <x-chat.list-head search="Имя, ТС, номер" :url="$base" :count="$chats->total() + ($support ? 1 : 0)" :q="$q ?? ''" :people="\App\Chats\Direct::partners($me)->isNotEmpty()"/>
            <div class="chat-rows-body" id="chat-rows-body">
                @php $people ??= collect(); @endphp
                @if ($chats->isEmpty() && ! $support && $people->isEmpty() && ! \App\Chats\Direct::platformHit($me, $q ?? ''))
                    <x-ui.empty>{{ ($q ?? '') !== '' ? 'Ничего не нашли' : 'Чатов нет' }}</x-ui.empty>
                @else
                    <div class="chat-rows-list" data-fresh-on-back>
                        <x-chat.people :people="$people" :me="$me" :base="$base" :current="$current"/>
                        @foreach ($chats as $c)
                            <x-chat.row :chat="$c" :me="$me" :href="$base.'/'.$c->id" :current="$c->id === $current"/>
                        @endforeach
                        {{-- Обращения ещё нет — та же строка, что у чата: аватар площадки и имя (покупателю — его менеджер), пустой
                             экран по нажатию. --}}
                        @if ($support)
                            <a href="{{ $base }}/support" class="chat-row" data-search-row @if ($current === 'support') aria-current="true" @endif data-turbo-action="advance">
                                @if ($supportTo)<x-ui.avatar :user="$supportTo" :size="44"/>@else<x-chat.avatar :size="44"/>@endif
                                <div class="min-w-0 flex-1"><span class="truncate">{{ $supportTo?->name ?? \App\Chats\Chat::PLATFORM }}</span></div>
                            </a>
                        @endif
                    </div>
                    <x-ui.pager :of="$chats" :sizes="[]"/>
                @endif
            </div>
        </div>
        <turbo-frame id="chat-screen" class="chat-pane" target="_top">
            @if ($current)
                <x-chat.screen :back="$base" :chat="$chat" :offer="$offer" :messages="$messages" :user="$me" :name="$name" :other="$other" :open="match ($current) { 'support' => '/chats/support', 'with' => '/chats/with/'.$with->id, default => null }" :greet="$current !== 'with'" :first-unread="$firstUnread" :more="$more" :link="$link" :sheet="! $mine" :status="$status" :chats="$others"/>
            @else
                <x-chat.empty/>
            @endif
        </turbo-frame>
    </div>
