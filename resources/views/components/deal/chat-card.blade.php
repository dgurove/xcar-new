{{-- Чат площадки с менеджером сделки — карточкой наверху: в карточке строки «Сделок» сразу под шапкой, в редакторе первым
     в правой колонке (07.10.2026, владелец: «чат на видное место, с аватаркой и последним сообщением, чтобы было прям
     понятно, что это чат»). Как диалог в списке мессенджера: аватар менеджера с пузырём в углу, имя, время, последнее
     сообщение, у непрочитанного — счётчик. Переписки нет — «Сообщений пока нет» (строка и так ссылка, 11.11.2026), чат заводится нажатием
     (`Admin\ChatController::start`). Новое сообщение по этой машине перечитывает карточку само (`reload` по `live:chat` с
     отбором по `offer`: сотрудникам идут сообщения всех чатов). chat — уже выбранный (редактор берёт из своих `$chats`),
     null — переписки нет; не передан — карточка выбирает сама. --}}
@props(['deal', 'chat' => false, 'compact' => false, 'inline' => false])
@php
    $offer = $deal->offer;
    $buyer = $deal->buyer;
    if ($chat === false) {
        $chat = \App\Chats\Chat::withLast()->platformWith($offer, $buyer)->first();
    }
    $unread = (int) ($chat?->unread_for_staff ?? 0);
@endphp
@if ($inline)
{{-- Тихой ссылкой «Чат» в плашке менеджера окна машины (12.11.2026, владелец: «кнопку чат в блок, где менеджер написан»):
     непрочитанные — оранжевым со счётчиком; переписки нет — та же ссылка заводит чат. --}}
<turbo-frame id="deal-chat-inline-{{ $deal->id }}" class="contents" data-controller="reload" data-reload-url-value="/work/deals/{{ $deal->id }}/chat?inline=1" data-reload-key-value="offer" data-reload-match-value="{{ $offer->id }}" data-action="live:chat@document->reload#load">
    @if ($chat)
        <a href="/work/chats/{{ $chat->id }}" data-turbo-frame="_top" @class(['holder-act', 'holder-act--hot' => $unread])><x-ui.icon name="chat" class="size-4"/>Чат@if ($unread) <span class="badge">{{ $unread }}</span>@endif</a>
    @else
        <form method="post" action="/work/chats/offer/{{ $offer->number }}/{{ $buyer->id }}" class="contents" data-turbo-frame="_top">@csrf<button type="submit" class="holder-act"><x-ui.icon name="chat" class="size-4"/>Чат</button></form>
    @endif
</turbo-frame>
@elseif ($compact)
{{-- Пилюлей «Чат» в ряду окошка сделки (12.10.2026, владелец: «Чат вот так красиво компактно везде и у сделок»): число
     непрочитанных — счётчиком, пилюля оранжевая; переписки нет — та же пилюля заводит чат. --}}
<turbo-frame id="deal-chat-pill-{{ $deal->id }}" class="contents" data-controller="reload" data-reload-url-value="/work/deals/{{ $deal->id }}/chat?compact=1" data-reload-key-value="offer" data-reload-match-value="{{ $offer->id }}" data-action="live:chat@document->reload#load">
    @if ($chat)
        <x-ui.pill :tone="$unread ? 'urgent' : 'plain'" href="/work/chats/{{ $chat->id }}" data-turbo-frame="_top"><x-ui.icon name="chat" class="size-4"/> Чат@if ($unread) <span class="badge">{{ $unread }}</span>@endif</x-ui.pill>
    @else
        <form method="post" action="/work/chats/offer/{{ $offer->number }}/{{ $buyer->id }}" class="contents" data-turbo-frame="_top">@csrf<button type="submit" class="pill pill-plain"><x-ui.icon name="chat" class="size-4"/> Чат</button></form>
    @endif
</turbo-frame>
@else
<turbo-frame id="deal-chat-{{ $deal->id }}" {{ $attributes->class(['block min-w-0']) }} data-controller="reload" data-reload-url-value="/work/deals/{{ $deal->id }}/chat" data-reload-key-value="offer" data-reload-match-value="{{ $offer->id }}" data-action="live:chat@document->reload#load">
    @if ($chat)
        <a href="/work/chats/{{ $chat->id }}" data-turbo-frame="_top" class="box deal-chat" aria-label="Чат с {{ $buyer->shortName() }}">
    @else
        <form method="post" action="/work/chats/offer/{{ $offer->number }}/{{ $buyer->id }}" class="contents" data-turbo-frame="_top">
            @csrf
            <button type="submit" class="box deal-chat w-full text-left" aria-label="Чат с {{ $buyer->shortName() }}">
    @endif
        <x-chat.card-body :chat="$chat" :title="$buyer->shortName()" :unread="$unread" :user="$buyer"/>
    @if ($chat)
        </a>
    @else
            </button>
        </form>
    @endif
</turbo-frame>
@endif
