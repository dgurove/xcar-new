{{-- Выдача поиска в шапке (`Search\Search::for`): группы строками `.list`, у машины — номер убытка с логотипом вендора
     (CRM и парковка) и фраза «где» кусками, у незаведённого номера из таблицы Кати — «Завести» рядом со строкой.
     Строки с `data-hit` листаются ↑/↓ и открываются Enter (global_search_controller). --}}
@if ($groups)
    <div class="search-results">
        @foreach ($groups as $key => $group)
            <div class="list-head">{{ $group['title'] }}</div>
            <div class="list">
                @foreach ($group['rows'] as $hit)
                    <div class="search-hit">
                        <a href="{{ $hit->href }}" class="row min-w-0 flex-1" data-hit data-turbo-frame="_top" @if ($hit->external) data-turbo="false" @endif>
                            <x-ui.row-icon :name="$hit->icon" size="s"/>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">{{ $hit->title }}</span>
                                @if ($hit->ref || $hit->line)
                                    <span class="row-sub search-line">
                                        @if ($hit->ref && $hit->ref !== $hit->title)<span class="search-ref"><x-vendor.ref :vendor="$hit->vendorId ? \App\Vendors\Vendor::badges()->get($hit->vendorId) : null" :ref="$hit->ref"/></span>@endif
                                        @foreach ($hit->line as $part)<span>{{ $part }}</span>@endforeach
                                    </span>
                                @endif
                            </span>
                        </a>
                        @if ($hit->action)
                            <form method="post" action="{{ $hit->action['url'] }}" data-turbo-frame="_top" class="shrink-0 pr-3">@csrf<button class="pill pill-accent !min-h-6 !py-0 !px-3 text-sm">{{ $hit->action['label'] }}</button></form>
                        @endif
                    </div>
                @endforeach
                @isset ($group['more'])
                    <a href="{{ $group['more']['href'] }}" class="row text-ink-muted" data-hit data-turbo-frame="_top"><span class="flex-1">{{ $group['more']['label'] }}</span><x-ui.chevron/></a>
                @endisset
            </div>
        @endforeach
    </div>
@elseif (mb_strlen($q) >= 2)
    <p class="px-1 py-6 text-center text-sm text-ink-muted">Ничего не нашлось</p>
@endif
