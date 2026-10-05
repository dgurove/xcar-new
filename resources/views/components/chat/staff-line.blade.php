{{-- Чат площадки с менеджером по этой машине — строкой в карточке гаража, сделки и вывоза CRM (05.10.2026, владелец:
     «пока тачка в гараже у менеджера, писать ему, задавать вопросы»). Есть переписка — её строка с последним и бейджем;
     нет — «Написать» с именем, чат заводится нажатием (`Admin\ChatController::start`), сообщения менеджер получает и в Telegram. --}}
@props(['offer', 'user'])
@php
    $chat = \App\Chats\Chat::withLast()->where('offer_id', $offer->id)->where('user_id', $user->id)->whereNull('manager_id')
        ->where('messages_count', '>', 0)->first();
@endphp
<div {{ $attributes->class(['list']) }}>
    @if ($chat)
        <x-chat.row :chat="$chat" :me="auth()->user()" :href="'/work/chats/'.$chat->id" staff/>
    @else
        <form method="post" action="/work/chats/offer/{{ $offer->number }}/{{ $user->id }}" class="contents" data-turbo-frame="_top">
            @csrf
            <button type="submit" class="row w-full text-left">
                <x-ui.row-icon name="chat" tone="accent" size="s"/>
                <span class="min-w-0 flex-1">Написать</span>
                <span class="shrink-0 text-ink-muted">{{ $user->shortName() }}</span>
                <x-ui.chevron/>
            </button>
        </form>
    @endif
</div>
