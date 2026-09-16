<x-ui.cabinet title="Уведомления">
    {{-- Ряд постоянной высоты: «Всё прочитано», когда есть что, и круглая шестерёнка настроек (та же шторка, что в профиле). --}}
    <div class="flex h-10 items-center gap-2" data-controller="sheet">
        @if ($unread)<form method="post" action="/lk/uvedomleniya/prochitano" class="contents">@csrf<x-ui.button variant="secondary" size="s">Всё прочитано</x-ui.button></form>@endif
        <button type="button" class="btn btn-quiet btn-round ml-auto" data-action="sheet#open" aria-label="Настройки уведомлений"><x-ui.icon name="settings" class="size-5"/></button>
        @include('cabinet.notification-settings')
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
