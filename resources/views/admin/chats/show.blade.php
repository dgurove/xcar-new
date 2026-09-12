@php $name = $chat->displayName(); @endphp
<x-ui.shell :title="$name" :trail="[['Главная', '/'], ['Переписки', '/perepiski'], ['Чаты', '/perepiski/chaty'], [$name]]" narrow>
    @if ($chat->offer)
        <a href="/predlozheniya/{{ $chat->offer->number }}" class="row mb-3">
            <div class="row-photo"><x-offer.photo :media="$chat->offer->mainPhoto()" sizes="64px"/></div>
            <div class="min-w-0 flex-1">
                <div class="truncate font-medium">№ {{ $chat->offer->number }} · {{ $chat->offer->titleWithYear() }}</div>
                <div class="text-sm text-ink-muted"><a href="tel:+{{ $chat->user->phone }}" class="text-accent-text">{{ $chat->user->phoneFormatted() }}</a></div>
            </div>
            <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
        </a>
    @else
        <div class="row mb-3">
            <div class="row-photo"><div class="flex size-full items-center justify-center text-ink-dim"><x-ui.icon name="chat" class="size-7"/></div></div>
            <div class="min-w-0 flex-1">
                <div class="truncate font-medium">Обращение с сайта</div>
                <div class="text-sm text-ink-muted">
                    @if ($chat->user)<a href="tel:+{{ $chat->user->phone }}" class="text-accent-text">{{ $chat->user->phoneFormatted() }}</a>@else<span class="tag">гость</span>@endif
                </div>
            </div>
        </div>
    @endif
    <x-ui.card class="!p-3">
        <x-chat.box :chat="$chat" :messages="$messages" :user="$user" :tall="true"/>
    </x-ui.card>
</x-ui.shell>
