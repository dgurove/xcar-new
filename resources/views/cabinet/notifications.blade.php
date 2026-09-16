<x-ui.cabinet title="Уведомления">
    {{-- Первая строка — настройки: что включено — чипами; «Всё прочитано» справа, когда есть что. --}}
    @php $st = auth()->user()->notification_settings ?? []; @endphp
    <div class="row relative">
        <a href="/lk/uvedomleniya/nastroyki" class="absolute inset-0 rounded-(--radius-l)" aria-label="Настройки уведомлений"></a>
        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="settings" class="size-5"/></span>
        <span class="min-w-0 flex-1">
            <span class="block font-medium">Настройки</span>
            <span class="row-sub">
                @if (auth()->user()->email && auth()->user()->wantsMail())<span class="tag">почта</span>@endif
                @if ($st['quiet'] ?? false)<span class="tag">тихие часы</span>@endif
                @if (($st['off'] ?? []) !== [])<span class="tag nums">выключено {{ count($st['off']) }}</span>@endif
            </span>
        </span>
        @if ($unread)<form method="post" action="/lk/uvedomleniya/prochitano" class="relative z-10 shrink-0">@csrf<x-ui.button variant="secondary" size="s">Всё прочитано</x-ui.button></form>@endif
        <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
    </div>

    @if ($items->isEmpty())
        <x-ui.empty>Уведомлений пока нет.</x-ui.empty>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($items as $item)
                @include('cabinet.notification-row')
            @endforeach
        </div>
        {{ $items->links() }}
    @endif
</x-ui.cabinet>
