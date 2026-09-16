<x-ui.cabinet title="Чаты">
    @if ($chats->isEmpty())
        <x-ui.empty href="/contacts" link="Написать нам">Чатов пока нет</x-ui.empty>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($chats as $chat)
                <a href="{{ $chat->offer ? '/offers/'.$chat->offer->number.'?chat=1' : '/contacts' }}" class="row items-start">
                    <div class="row-photo">
                        @if ($chat->offer)<x-offer.photo :media="$chat->offer->mainPhoto()" sizes="64px"/>
                        @else<div class="flex size-full items-center justify-center text-ink-dim"><x-ui.icon name="chat" class="size-7"/></div>@endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate {{ $chat->unread_for_user ? 'font-medium' : '' }}">{{ $chat->offer ? $chat->offer->titleWithYear() : 'Обращение' }}</span>
                            <span class="ml-auto shrink-0 text-sm text-ink-dim">{{ $chat->last_message_at?->translatedFormat($chat->last_message_at->isToday() ? 'H:i' : 'j M') }}</span>
                        </div>
                        <div class="truncate text-sm {{ $chat->unread_for_user ? '' : 'text-ink-muted' }}">{{ $chat->last_text ? \Illuminate\Support\Str::limit($chat->last_text, 90) : 'Файл' }}</div>
                    </div>
                    @if ($chat->unread_for_user)<span class="badge">{{ $chat->unread_for_user }}</span>@endif
                </a>
            @endforeach
        </div>
        {{ $chats->links() }}
    @endif
</x-ui.cabinet>
