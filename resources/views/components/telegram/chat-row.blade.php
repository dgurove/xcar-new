{{-- Строка чата бота: кружок человека, имя (аккаунт xcar или имя в Telegram), роль, время, последнее сообщение
     («Бот: …» — написал бот), «остановил», если человек заблокировал бота. --}}
@props(['chat', 'href', 'current' => false])
@php $at = $chat->last_message_at; @endphp
<a href="{{ $href }}" class="chat-row" data-search-row @if ($current) aria-current="true" @endif data-turbo-action="advance">
    <x-telegram.avatar :chat="$chat" :size="44"/>
    <div class="min-w-0 flex-1">
        <div class="flex items-baseline gap-2">
            <span class="truncate">{{ $chat->displayName() }}</span>
            @if ($role = $chat->roleLabel())<span class="tag shrink-0">{{ $role }}</span>@endif
            @if ($chat->left_at)<span class="tag tag-danger shrink-0">остановил</span>@endif
            @if ($at)<span class="ml-auto shrink-0 text-xs text-ink-dim nums">{{ $at->translatedFormat($at->isToday() ? 'H:i' : ($at->year === now()->year ? 'j M' : 'd.m.y')) }}</span>@endif
        </div>
        <div class="truncate text-sm text-ink-muted">{{ $chat->lastPreview() }}</div>
    </div>
</a>
