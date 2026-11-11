{{-- Содержимое карточки чата сделки — как диалог в списке мессенджера: слева значок (аватар собеседника с пузырём или
     пузырь), имя, время, последнее сообщение, у непрочитанного — счётчик; переписки нет — «Сообщений пока нет». Одно на
     сторону менеджера (`cabinet.deals.chat-card`, «Чат с XCar») и сотрудника (`x-deal.chat-card`, менеджер сделки) —
     11.11.2026: было две копии разметки. user — аватар собеседника; без него — значок пузыря (лаймовый у непрочитанного). --}}
@props(['chat', 'title', 'unread' => 0, 'user' => null])
@if ($user)
    <span class="deal-chat-avatar">
        <x-chat.avatar :user="$user" :size="44"/>
        <span class="deal-chat-bubble"><x-ui.icon name="chat" class="size-3"/></span>
    </span>
@else
    <x-ui.row-icon name="chat" :tone="$unread ? 'accent' : 'plain'"/>
@endif
<span class="min-w-0 flex-1">
    <span class="flex items-baseline gap-2">
        <span class="truncate font-medium">{{ $title }}</span>
        @if ($chat?->last_message_at)<span class="nums ml-auto shrink-0 text-xs text-ink-dim">{{ \App\Support\Ago::html($chat->last_message_at, short: true) }}</span>@endif
    </span>
    <span class="mt-0.5 flex items-center gap-2">
        @if ($chat)
            <span @class(['truncate text-sm', 'text-ink-muted' => ! $unread])>{{ $chat->lastPreview(auth()->user()) }}</span>
            @if ($unread)<span class="badge ml-auto">{{ $unread }}</span>@endif
        @else
            <span class="text-sm text-ink-muted">Сообщений пока нет</span>
        @endif
    </span>
</span>
@unless ($unread)<x-ui.chevron/>@endunless
