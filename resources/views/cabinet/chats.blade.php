<x-ui.cabinet title="Чаты">
    @if ($chats->isEmpty())
        <x-ui.empty href="/contacts" link="Написать нам">Чатов пока нет</x-ui.empty>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($chats as $chat)
                @php $mine = $chat->user_id === auth()->id(); $unread = $mine ? $chat->unread_for_user : $chat->unread_for_staff; @endphp
                {{-- Свой чат открывается в шторке предложения; чат покупателя — своим экраном. --}}
                <a href="{{ !$mine ? '/account/chats/'.$chat->id : ($chat->offer ? '/offers/'.$chat->offer->number.'?chat=1' : '/contacts') }}" class="row items-start">
                    <div class="row-photo">
                        @if ($chat->offer)<x-offer.photo :media="$chat->offer->mainPhoto()" sizes="64px"/>
                        @else<div class="flex size-full items-center justify-center text-ink-dim"><x-ui.icon name="chat" class="size-7"/></div>@endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate {{ $unread ? 'font-medium' : '' }}">{{ $chat->offer ? $chat->offer->titleWithYear() : 'Обращение' }}</span>
                            <span class="ml-auto shrink-0 text-sm text-ink-dim">{{ $chat->last_message_at?->translatedFormat($chat->last_message_at->isToday() ? 'H:i' : 'j M') }}</span>
                        </div>
                        @unless ($mine)<div class="mt-0.5"><x-ui.person :user="$chat->user"/></div>@endunless
                        <div class="truncate text-sm {{ $unread ? '' : 'text-ink-muted' }}">{{ $chat->last_text ? \Illuminate\Support\Str::limit($chat->last_text, 90) : 'Файл' }}</div>
                    </div>
                    @if ($unread)<span class="badge">{{ $unread }}</span>@endif
                </a>
            @endforeach
        </div>
        {{ $chats->links() }}
    @endif
</x-ui.cabinet>
