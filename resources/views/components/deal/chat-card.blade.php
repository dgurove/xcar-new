{{-- Чат площадки с менеджером сделки — карточкой наверху: в карточке строки «Сделок» сразу под шапкой, в редакторе первым
     в правой колонке (07.10.2026, владелец: «чат на видное место, с аватаркой и последним сообщением, чтобы было прям
     понятно, что это чат»). Как диалог в списке мессенджера: аватар менеджера с пузырём в углу, имя, время, последнее
     сообщение, у непрочитанного — счётчик. Переписки нет — «Написать сообщение», чат заводится нажатием
     (`Admin\ChatController::start`). Новое сообщение перечитывает карточку само (`reload` по `live:chat`). --}}
@props(['deal'])
@php
    $offer = $deal->offer;
    $buyer = $deal->buyer;
    $chat = \App\Chats\Chat::withLast()->where('offer_id', $offer->id)->where('user_id', $buyer->id)->whereNull('manager_id')
        ->where('messages_count', '>', 0)->first();
    $unread = (int) ($chat?->unread_for_staff ?? 0);
    $at = $chat?->last_message_at;
@endphp
<turbo-frame id="deal-chat-{{ $deal->id }}" {{ $attributes->class(['block min-w-0']) }} data-controller="reload" data-reload-url-value="/work/deals/{{ $deal->id }}/chat" data-action="live:chat@document->reload#load">
    @if ($chat)
        <a href="/work/chats/{{ $chat->id }}" data-turbo-frame="_top" class="box deal-chat" aria-label="Чат с {{ $buyer->shortName() }}">
    @else
        <form method="post" action="/work/chats/offer/{{ $offer->number }}/{{ $buyer->id }}" class="contents" data-turbo-frame="_top">
            @csrf
            <button type="submit" class="box deal-chat w-full text-left" aria-label="Чат с {{ $buyer->shortName() }}">
    @endif
        <span class="deal-chat-avatar">
            <x-chat.avatar :user="$buyer" :size="44"/>
            <span class="deal-chat-bubble"><x-ui.icon name="chat" class="size-3"/></span>
        </span>
        <span class="min-w-0 flex-1">
            <span class="flex items-baseline gap-2">
                <span class="truncate font-medium">{{ $buyer->shortName() }}</span>
                @if ($at)<span class="nums ml-auto shrink-0 text-xs text-ink-dim">{{ $at->translatedFormat($at->isToday() ? 'H:i' : ($at->year === now()->year ? 'j M' : 'd.m.y')) }}</span>@endif
            </span>
            <span class="mt-0.5 flex items-center gap-2">
                @if ($chat)
                    <span @class(['truncate text-sm', 'text-ink-muted' => ! $unread])>{{ $chat->lastPreview(auth()->user()) }}</span>
                    @if ($unread)<span class="badge ml-auto">{{ $unread }}</span>@endif
                @else
                    <span class="text-sm text-accent-text">Написать сообщение</span>
                @endif
            </span>
        </span>
        @unless ($unread)<x-ui.chevron/>@endunless
    @if ($chat)
        </a>
    @else
            </button>
        </form>
    @endif
</turbo-frame>
