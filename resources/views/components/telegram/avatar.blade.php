{{-- Кружок собеседника бота: аккаунт xcar — его аватар, иначе инициалы имени из Telegram; чат владельца без аккаунта — знак Telegram. --}}
@props(['chat', 'size' => 40])
@if ($chat->user)
    <x-ui.avatar :user="$chat->user" :size="$size" {{ $attributes }}/>
@elseif (! $chat->name && $chat->isOwner())
    <x-telegram.logo {{ $attributes }} style="width: {{ $size }}px; height: {{ $size }}px"/>
@else
    <x-ui.avatar :name="$chat->name ?? $chat->username ?? (string) $chat->id" :size="$size" {{ $attributes }}/>
@endif
