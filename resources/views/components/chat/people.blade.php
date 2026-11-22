{{-- Люди первыми в поиске чатов (22.11.2026, менеджеры: «начинаешь вбивать — этот вариант должен быть самым первым»): кому
     можно написать и чьё имя подходит (`Chats\Direct::people`). Есть переписка — обычной строкой чата; нет — строка человека
     с лаймовым «Написать», нажатие — пустой экран лички (`/with/{user}`). Менеджеру под запрос «админ…» — «Администрация
     XCAR». Строки — внутри `#chat-rows-body`: живой поиск подменяет их вместе со списком. --}}
@props(['people', 'me', 'base', 'qs' => '', 'current' => null, 'staff' => false])
@php
    use App\Chats\Chat;
    use App\Chats\Direct;
    $q = trim((string) request()->query('q'));
    $platform = ! $staff && Direct::platformHit($me, $q);
@endphp
@if ($platform)
    <a href="{{ $base }}/support" class="chat-row" data-search-row data-turbo-action="advance">
        <x-chat.avatar :size="44"/>
        <div class="min-w-0 flex-1"><span class="block truncate">{{ Chat::PLATFORM }}</span><span class="block text-sm text-accent-text">Написать</span></div>
    </a>
@endif
@foreach ($people as $p)
    @if ($p['chat'] && $p['chat']->messages_count > 0)
        <x-chat.row :chat="$p['chat']" :me="$me" :href="$base.'/'.$p['chat']->id.$qs" :current="$p['chat']->id === $current"/>
    @else
        <a href="{{ Chat::directPath($p['user']->id) }}" class="chat-row" data-search-row data-turbo-action="advance">
            <x-ui.avatar :user="$p['user']" :size="44"/>
            <div class="min-w-0 flex-1"><span class="block truncate">{{ $p['user']->name }}</span><span class="block text-sm text-accent-text">Написать</span></div>
        </a>
    @endif
@endforeach
