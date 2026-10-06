{{-- Чат по сделке — карточкой над «Расчётом», как диалог в списке мессенджера (06.10.2026, владелец: «кнопка чата — в
     блоке над продажей, и писать там, если новое сообщение»): пузырь, «Чат с XCar», время, последнее сообщение, у
     непрочитанного — лаймовый значок и счётчик. Переписки ещё нет — «Написать сообщение». Новое сообщение
     перечитывает карточку само (`reload` по `live:chat`). --}}
@php
    $chat = \App\Chats\Chat::withLast()->where('offer_id', $offer->id)->where('user_id', auth()->id())->whereNull('manager_id')->first();
    $chat = $chat?->last_message_at ? $chat : null;
    $unread = (int) ($chat?->unread_for_user ?? 0);
    $at = $chat?->last_message_at;
@endphp
<turbo-frame id="deal-chat" data-controller="reload" data-reload-url-value="/deals/{{ $deal->id }}/chat" data-action="live:chat@document->reload#load">
    {{-- Что это чат — видно сразу (владелец: «непонятно, что это чат» при знаке XCar и «Администрация XCar»): значок
         пузыря, «Чат с XCar», под ним последнее сообщение или «Написать сообщение». --}}
    <a href="/account/chats/offer/{{ $offer->number }}" target="_top" class="box deal-chat">
        <x-ui.row-icon name="chat" :tone="$unread ? 'accent' : 'plain'"/>
        <span class="min-w-0 flex-1">
            <span class="flex items-baseline gap-2">
                <span class="truncate font-medium">Чат с XCar</span>
                @if ($at)<span class="nums ml-auto shrink-0 text-xs text-ink-dim">{{ $at->translatedFormat($at->isToday() ? 'H:i' : 'j M') }}</span>@endif
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
    </a>
</turbo-frame>
