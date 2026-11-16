{{-- Шапка вкладок колеса «Предложений» — одна на пять видов: предложения, сделки, гараж, галерея, закупки (17.11.2026, владелец:
     «во всех вкладках шапки привести к единому модулю»). Ряды:
     1 — название-колесо (`x-ui.tab-title`) и действия экрана (слот `actions`: «Из писем», «+ Новый», выгрузка);
     2 — только кнопки: слева сортировка и лупа, справа вид и «Фильтр» (`x-ui.toolbar rows`);
     3 — быстрые чипы вкладки (`Facet::quick`) и выбранное в «Фильтре» чипами с ×;
     4 — переключатели вкладки («Горит срок», «Переоценка»).
     Пустой ряд не рисуется. Ссылки колеса несут только вид (`keep`): чипы каждая вкладка берёт из своей памяти. --}}
@props(['presets', 'preset', 'counts' => [], 'sections' => [], 'sort' => null, 'name' => 'list', 'facets' => null, 'search' => null, 'views' => null, 'view' => false, 'fallback' => 'Предложения'])
<div class="title-row">
    <x-ui.tab-title :pills="$presets" :pill="$preset" pill-param="preset" pill-home="" :counts="$counts" :badges="\App\Http\Admin\OfferController::badges()" :bumps="['legacy' => 'legacy_drafts']" :sections="$sections" :keep="[\App\Support\ListView::PARAM]" :fallback="$fallback" id="offers-tabs"/>
    @isset($actions)
        <div class="title-actions">{{ $actions }}</div>
    @endisset
</div>
<x-ui.toolbar rows :sort="$sort" :name="$name" :facets="$facets" :search="$search">
    @if ($views)
        <x-slot:extra><x-ui.view-switch :current="$view" :views="$views"/></x-slot:extra>
    @endif
</x-ui.toolbar>
