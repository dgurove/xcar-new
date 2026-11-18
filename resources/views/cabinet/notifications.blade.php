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
        <form method="post" action="/account/notifications/read" class="-mb-4 -mt-2 flex justify-end">@csrf<button class="btn btn-s btn-quiet"><x-ui.icon name="check" class="size-4"/> Всё прочитано</button></form>
    @endif
    {{-- Фильтр (18.11.2026): все, «Ждут нас» (важные, непрочитанные — числом), непрочитанные. --}}
    <x-ui.pills>
        <x-ui.pill href="/account/notifications" :current="$show === 'all'" :tone="$show === 'all' ? 'accent' : null">Все</x-ui.pill>
        <x-ui.pill href="/account/notifications?show=ours" :current="$show === 'ours'" :tone="$show === 'ours' ? 'accent' : null">Ждут нас @if ($counts['ours'])<span class="nums opacity-70">{{ $counts['ours'] }}</span>@endif</x-ui.pill>
        <x-ui.pill href="/account/notifications?show=unread" :current="$show === 'unread'" :tone="$show === 'unread' ? 'accent' : null">Непрочитанные @if ($counts['unread'])<span class="nums opacity-70">{{ $counts['unread'] }}</span>@endif</x-ui.pill>
    </x-ui.pills>
    @if ($items->isEmpty())
        <x-ui.empty>{{ $show === 'all' ? 'Уведомлений пока нет' : 'Здесь пусто' }}</x-ui.empty>
    @else
        {{-- Группами по дням: у строки только время. --}}
        @foreach ($items->getCollection()->groupBy(fn ($i) => \App\Notifications\NoticeFaces::day($i->created_at)) as $day => $group)
            <section>
                <h2 class="list-head">{{ $day }}</h2>
                <div class="list" data-fresh-on-back>
                    @foreach ($group as $item)
                        @include('cabinet.notification-row')
                    @endforeach
                </div>
            </section>
        @endforeach
        <x-ui.pager :of="$items" :sizes="[]"/>
    @endif
</x-ui.cabinet>
