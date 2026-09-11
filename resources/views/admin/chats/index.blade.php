<x-ui.shell title="Чаты" :wide="true">
    <div class="mb-4 flex flex-col gap-3">
        <x-ui.switch :items="['/admin/pochta' => 'Письма', '/admin/chaty' => 'Чаты']" current="/admin/chaty"/>
        <form method="get" data-controller="autosubmit">
            @if ($preset !== 'unread')<input type="hidden" name="preset" value="{{ $preset }}">@endif
            <label class="relative block">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-dim"/>
                <input type="search" name="q" value="{{ $q }}" placeholder="Имя, телефон, номер оффера" class="field-input !bg-surface pl-11" enterkeyhint="search">
            </label>
        </form>
        <x-ui.presets :items="\App\Http\Admin\ChatController::PRESETS" :current="$preset" :counts="['unread' => $unread]"/>
    </div>
    @if ($chats->isEmpty())
        <div class="py-24 text-center text-ink-muted">Чатов нет</div>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($chats as $chat)
                <a href="/admin/chaty/{{ $chat->id }}" class="row items-start">
                    <div class="row-photo"><x-offer.photo :media="$chat->offer->mainPhoto()" sizes="64px"/></div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate {{ $chat->unread_for_staff ? 'font-medium' : '' }}">{{ $chat->user->name }}</span>
                            <span class="ml-auto shrink-0 text-sm text-ink-dim tabular-nums">{{ $chat->last_message_at?->translatedFormat($chat->last_message_at->isToday() ? 'H:i' : 'j M') }}</span>
                        </div>
                        <div class="truncate text-sm text-ink-muted">№ {{ $chat->offer->number }} · {{ $chat->offer->titleWithYear() }}</div>
                    </div>
                    @if ($chat->unread_for_staff)<span class="badge">{{ $chat->unread_for_staff }}</span>@endif
                </a>
            @endforeach
        </div>
        <div class="mt-4">{{ $chats->links() }}</div>
    @endif
</x-ui.shell>
