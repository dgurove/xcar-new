<x-ui.shell title="Чаты" :heading="false" :trail="[['Главная', '/'], ['Переписки', '/perepiski'], ['Чаты']]">
    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
        <x-ui.section-title href="/perepiski/pochta" :current="false" :count="$mailUnread ?: null">Почта</x-ui.section-title>
        <x-ui.section-title level="h1" :count="$chats->total()">Чаты</x-ui.section-title>
    </div>

    <x-ui.toolbar class="mt-5" :pills="\App\Http\Admin\ChatController::PRESETS" :pill="$preset" pill-param="preset" :counts="['unread' => $unread]" name="chats">
        <x-slot:filters>
            <input name="q" value="{{ $q }}" placeholder="Имя, телефон, номер предложения" class="field-input field-s">
        </x-slot:filters>
    </x-ui.toolbar>
    @if ($chats->isEmpty())
        <x-ui.empty class="mt-6">Чатов нет.</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2">
            @foreach ($chats as $chat)
                <a href="/perepiski/chaty/{{ $chat->id }}" class="row items-start">
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
        <div class="mt-8">{{ $chats->links() }}</div>
    @endif
</x-ui.shell>
