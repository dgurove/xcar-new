{{-- Шторка-контакт из шапки чата: крупный аватар, имя, статус, «Позвонить» (если есть телефон),
     ниже — остальные чаты с этим человеком. Площадка (user пуст) — знак приложения, без звонка. --}}
@props(['user' => null, 'name', 'status' => null, 'chats' => null, 'me'])
<x-ui.sheet id="chat-contact">
    <div class="-mt-8 flex flex-col items-center text-center">
        <x-chat.avatar :user="$user" :size="72" :online="$status === 'в сети'" class="text-2xl"/>
        <div class="mt-3 text-xl font-medium">{{ $name }}</div>
        @if ($status)<div class="text-sm text-ink-muted">{{ $status }}</div>@endif
        @if ($user?->phone)
            <div class="acts mt-4 justify-center">
                <a href="tel:+{{ $user->phone }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="phone"/></span>Позвонить</a>
            </div>
        @endif
    </div>
    @if ($chats?->isNotEmpty())
        <h3 class="mt-6 text-lg">Ещё чаты</h3>
        <div class="mt-3 flex flex-col gap-2">
            @foreach ($chats as $c)
                <a href="/account/chats/{{ $c->id }}" class="row" data-turbo-action="replace">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2"><span class="truncate {{ $c->unread_for_user ? 'font-medium' : '' }}">{{ $c->offer?->titleWithYear() ?? \App\Chats\Chat::PLATFORM }}</span><span class="ml-auto shrink-0 text-sm text-ink-dim">{{ $c->last_message_at?->translatedFormat($c->last_message_at->isToday() ? 'H:i' : 'j M') }}</span></div>
                        <div class="truncate text-sm {{ $c->unread_for_user ? '' : 'text-ink-muted' }}">{{ $c->lastPreview($me) }}</div>
                    </div>
                    @if ($c->unread_for_user)<span class="badge">{{ $c->unread_for_user }}</span>@endif
                </a>
            @endforeach
        </div>
    @endif
</x-ui.sheet>
