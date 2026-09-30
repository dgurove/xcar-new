<x-ui.cabinet title="Уведомления">
    {{-- Первая строка — вход в настройки; «Всё прочитано» — текстовой кнопкой над лентой, когда есть что: в строке
         настроек она сжимала подпись до двух строк. --}}
    <a href="/account/notifications/settings" class="list">
        <span class="row">
            <x-ui.row-icon name="settings" size="s"/>
            <span class="min-w-0 flex-1">Настройки уведомлений</span>
            <x-ui.chevron/>
        </span>
    </a>

    @if ($unread)
        <form method="post" action="/account/notifications/read" class="-mb-4 -mt-2 flex justify-end">@csrf<button class="btn btn-s btn-ghost text-accent-text">Всё прочитано</button></form>
    @endif
    @if ($items->isEmpty())
        <x-ui.empty>Уведомлений пока нет</x-ui.empty>
    @else
        <div class="list">
            @foreach ($items as $item)
                @include('cabinet.notification-row')
            @endforeach
        </div>
        <x-ui.pager :of="$items" :sizes="[]"/>
    @endif
</x-ui.cabinet>
