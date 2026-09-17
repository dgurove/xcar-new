{{-- Чат покупателя с менеджером — экран менеджера в кабинете: строка ТС, покупатель с телефоном, лента. --}}
<x-ui.cabinet :title="$chat->user->name">
    @if ($chat->offer)
        <a href="/offers/{{ $chat->offer->number }}" class="row mb-3">
            <div class="row-photo"><x-offer.photo :media="$chat->offer->mainPhoto()" sizes="64px"/></div>
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-2"><span class="truncate font-medium">{{ $chat->offer->titleWithYear() }}</span><span class="nums shrink-0 text-sm text-ink-dim">№ {{ $chat->offer->number }}</span></div>
                <div class="mt-0.5 flex flex-wrap items-center gap-1.5"><x-ui.person :user="$chat->user"/>@if ($chat->user->phone)<a href="tel:+{{ $chat->user->phone }}" class="tag nums">{{ $chat->user->phoneFormatted() }}</a>@endif</div>
            </div>
            <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
        </a>
    @endif
    <x-ui.card class="!p-3">
        <x-chat.box :chat="$chat" :messages="$messages" :user="$user" :tall="true"/>
    </x-ui.card>
</x-ui.cabinet>
