<x-ui.cabinet title="Уведомления">
    {{-- Первая строка — вход в настройки; «Всё прочитано» справа, когда есть что. --}}
    <div class="row relative">
        <a href="/account/notifications/settings" class="absolute inset-0 rounded-(--radius-l)" aria-label="Настройки уведомлений"></a>
        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="settings" class="size-5"/></span>
        <span class="min-w-0 flex-1 font-medium">Настройки уведомлений</span>
        @if ($unread)<form method="post" action="/account/notifications/read" class="relative z-10 shrink-0">@csrf<x-ui.button variant="secondary" size="s">Всё прочитано</x-ui.button></form>@endif
        <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
    </div>

    @if ($items->isEmpty())
        <x-ui.empty>Уведомлений пока нет</x-ui.empty>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($items as $item)
                @include('cabinet.notification-row')
            @endforeach
        </div>
        <x-ui.pager :of="$items" :sizes="[]"/>
    @endif
</x-ui.cabinet>
