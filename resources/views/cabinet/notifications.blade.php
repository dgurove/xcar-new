<x-ui.cabinet title="Уведомления">
    {{-- Настройки уведомлений — в профиле; здесь только «Всё прочитано», когда есть что. --}}
    @if ($unread)
        <form method="post" action="/lk/uvedomleniya/prochitano" class="flex">@csrf<x-ui.button variant="secondary" size="s">Всё прочитано</x-ui.button></form>
    @endif

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
