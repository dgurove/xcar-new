<x-ui.shell title="Чаты" :heading="false">
    <x-admin.work-titles current="chaty" :count="$chats->total()"/>

    <x-ui.toolbar class="mt-5" :pills="\App\Http\Admin\ChatController::PRESETS" :pill="$preset" pill-param="preset" :counts="['unread' => $unread]" name="chats">
        <x-slot:filters>
            <input name="q" value="{{ $q }}" placeholder="Имя, телефон, номер предложения" class="field-input field-s">
        </x-slot:filters>
    </x-ui.toolbar>
    @if ($chats->isEmpty())
        <x-ui.empty class="mt-6">Чатов нет</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2">
            @foreach ($chats as $chat)
                <a href="/rabota/chaty/{{ $chat->id }}" class="row items-start">
                    <div class="row-photo">
                        @if ($chat->offer)<x-offer.photo :media="$chat->offer->mainPhoto()" sizes="64px"/>
                        @else<div class="flex size-full items-center justify-center text-ink-dim"><x-ui.icon name="chat" class="size-7"/></div>@endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate {{ $chat->unread_for_staff ? 'font-medium' : '' }}">{{ $chat->displayName() }}</span>
                            @unless ($chat->user)<span class="tag">гость</span>@endunless
                            <span class="ml-auto shrink-0 text-sm text-ink-dim">{{ $chat->last_message_at?->translatedFormat($chat->last_message_at->isToday() ? 'H:i' : 'j M') }}</span>
                        </div>
                        <div class="flex items-baseline gap-2 text-sm text-ink-muted">@if ($chat->offer)<span class="truncate">{{ $chat->offer->titleWithYear() }}</span><span class="nums shrink-0 text-ink-dim">№ {{ $chat->offer->number }}</span>@else<span>Обращение с сайта</span>@endif</div>
                    </div>
                    @if ($chat->unread_for_staff)<span class="badge">{{ $chat->unread_for_staff }}</span>@endif
                </a>
            @endforeach
        </div>
        <div class="mt-8">{{ $chats->links() }}</div>
    @endif
</x-ui.shell>
