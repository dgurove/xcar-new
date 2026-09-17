{{-- Чаты как в мессенджере: строки — собеседник, ТС, последнее сообщение, бейдж; на широком экране
     справа открытый чат (фрейм chat-screen, split_controller ведёт строки в него), на телефоне —
     либо список, либо экран (current). --}}
@php
    $me = auth()->user();
    $current ??= null;
@endphp
<x-ui.cabinet :title="$current ? $chat->counterpartName($me) : 'Чаты'">
    <div class="chat-split {{ $current ? 'has-current' : '' }}" data-controller="split">
        <div class="chat-rows">
            @if ($chats->isEmpty())
                <x-ui.empty href="/contacts" link="Написать нам">Чатов пока нет</x-ui.empty>
            @else
                @foreach ($chats as $c)
                    @php
                        $other = $c->isCounterpart($me) ? $c->user : $c->manager;
                        $unread = $c->isCounterpart($me) ? $c->unread_for_staff : $c->unread_for_user;
                        $preview = $c->last_deleted_at ? 'Сообщение удалено' : (($c->last_author_id === $me->id ? 'Вы: ' : '').($c->last_text ? \Illuminate\Support\Str::limit($c->last_text, 90) : 'Фото'));
                    @endphp
                    <a href="/account/chats/{{ $c->id }}" class="row chat-row" @if ($c->id === $current) aria-current="true" @endif data-turbo-action="advance">
                        <div class="row-photo">
                            @if ($c->offer)<x-offer.photo :media="$c->offer->mainPhoto()" sizes="64px"/>
                            @elseif ($other)<x-ui.avatar :user="$other" :size="52" class="!size-full !rounded-none"/>
                            @else<div class="flex size-full items-center justify-center text-ink-dim"><x-ui.icon name="chat" class="size-7"/></div>@endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="truncate {{ $unread ? 'font-medium' : '' }}">{{ $c->counterpartName($me) }}</span>
                                <span class="ml-auto shrink-0 text-sm text-ink-dim nums">{{ $c->last_message_at?->translatedFormat($c->last_message_at->isToday() ? 'H:i' : 'j M') }}</span>
                            </div>
                            @if ($c->offer)<div class="truncate text-sm text-ink-muted">{{ $c->offer->titleWithYear() }}</div>@else<div class="truncate text-sm text-ink-muted">Обращение</div>@endif
                            <div class="flex items-center gap-2"><span class="truncate text-sm {{ $unread ? '' : 'text-ink-muted' }}">{{ $preview }}</span>@if ($unread)<span class="badge ml-auto">{{ $unread }}</span>@endif</div>
                        </div>
                    </a>
                @endforeach
                {{ $chats->links() }}
            @endif
        </div>
        <turbo-frame id="chat-screen" class="chat-pane" target="_top">
            @if ($current)
                <x-chat.screen :chat="$chat" :messages="$messages" :user="$me" :name="$chat->counterpartName($me)" :other="$chat->isCounterpart($me) ? $chat->user : $chat->manager" :first-unread="$firstUnread" :more="$more"/>
            @else
                <div class="chat-none">Выберите чат</div>
            @endif
        </turbo-frame>
    </div>
</x-ui.cabinet>
