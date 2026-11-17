{{-- Шапка вкладок колеса «Предложений» — одна на пять видов: предложения, сделки, гараж, галерея, закупки (17.11.2026, владелец:
     «во всех вкладках шапки привести к единому модулю»). Ряды:
     1 — название-колесо (`x-ui.tab-title`: ˄ ˅ к соседней вкладке, на ПК колесо — тремя столбцами) и действия экрана (слот
         `actions`: «Из писем», «+ Новый», выгрузка);
     2 — только кнопки: слева лупа, сортировка, вид, справа «Фильтр» (`x-ui.toolbar rows`);
     3 — переключатели вкладки (`Facet::quick`: «Горит срок», «Переоценка»); остальное и что выбрано — в окне «Фильтр».
     Пустой ряд не рисуется; на ПК — те же ряды. Ссылки колеса несут только вид (`keep`): чипы каждая вкладка берёт из своей памяти. --}}
@props(['presets', 'preset', 'counts' => [], 'sections' => [], 'sort' => null, 'name' => 'list', 'facets' => null, 'search' => null, 'views' => null, 'view' => false, 'fallback' => 'Предложения'])
{{-- «Другие дела» сделок (наш шаг, что не подошёл ни под одну вкладку «Сделать») — только когда там что-то есть. --}}
@php($presets = ($counts['todo'] ?? 0) || $preset === 'todo' ? $presets : array_diff_key($presets, ['todo' => true]))
<div class="title-row">
    <x-ui.tab-title :pills="$presets" :pill="$preset" pill-param="preset" pill-home="" :counts="$counts" :badges="\App\Http\Admin\OfferController::badges()" :bumps="['legacy' => 'legacy_drafts']" :sections="$sections" :heads="['all', ...array_keys(\App\Http\Admin\OfferController::SUMMARIES)]" :waits="\App\Offers\DealTabs::WAITING" :columns="\App\Http\Admin\OfferController::wheelColumns(auth()->user())" :skip-empty="! $facets?->active()" :keep="[\App\Support\ListView::PARAM]" :fallback="$fallback" id="offers-tabs"/>
    @isset($actions)
        <div class="title-actions">{{ $actions }}</div>
    @endisset
</div>
<x-ui.toolbar rows :sort="$sort" :name="$name" :facets="$facets" :search="$search">
    @if ($views)
        <x-slot:extra><x-ui.view-switch :current="$view" :views="$views"/></x-slot:extra>
    @endif
</x-ui.toolbar>
