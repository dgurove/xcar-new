{{-- Шапка вкладок колеса «Предложений» — одна на пять видов: предложения, сделки, гараж, галерея, закупки (17.11.2026, владелец:
     «во всех вкладках шапки привести к единому модулю»). Ряды:
     1 — название-колесо (`x-ui.tab-title`: ˄ ˅ к соседней вкладке, на ПК колесо — тремя столбцами) и действия экрана (слот
         `actions`: «Из писем», «+ Новый», выгрузка);
     2 — только кнопки: слева лупа, сортировка, вид, справа «Фильтр» (`x-ui.toolbar rows`), все высотой 36 px; под ними слева «Выбрать» и быстрые чипы одной строкой;
     3 — переключатели вкладки (`Facet::quick`: «Горит срок», «Переоценка»); остальное и что выбрано — в окне «Фильтр».
     Пустой ряд не рисуется; на ПК — те же ряды. Ссылки колеса несут только вид (`keep`): чипы каждая вкладка берёт из своей памяти. --}}
@props(['presets', 'preset', 'counts' => [], 'sections' => [], 'sort' => null, 'name' => 'list', 'facets' => null, 'search' => null, 'views' => null, 'view' => false, 'fallback' => 'Предложения', 'select' => false])
{{-- «Не распределено» сделок (наш шаг, что не подошёл ни под одну вкладку «Сделать») — только когда там что-то есть. --}}
@php($presets = ($counts['todo'] ?? 0) || $preset === 'todo' ? $presets : array_diff_key($presets, ['todo' => true]))
{{-- Ссылки колеса несут и `mine_as` (24.11.2026): главный администратор смотрит «Только мои» сотрудника, и переход по вкладке
     выбор не теряет. --}}
{{-- У «Все сделки» в колесе красного счётчика нет (24.11.2026, владелец): дела видны по вкладкам сделки. --}}
<div class="title-row">
    <x-ui.tab-title :pills="$presets" :pill="$preset" pill-param="preset" pill-home="" :counts="$counts" :badges="array_diff_key(\App\Http\Admin\OfferController::badges(), ['all_deals' => true])" :bumps="['legacy' => 'legacy_drafts']" :sections="$sections" :heads="['all', ...array_keys(\App\Http\Admin\OfferController::SUMMARIES)]" :waits="[...array_diff(\App\Offers\DealTabs::WAITING, ['paying']), 'pickup_moving']" :dots="[...array_keys(\App\Offers\DealTabs::TABS), ...array_keys(\App\Http\Admin\PickupController::TABS)]" :columns="\App\Http\Admin\OfferController::wheelColumns(auth()->user())" :skip-empty="! $facets?->active()" :keep="[\App\Support\ListView::PARAM, 'mine_as']" :mine="\App\Http\Admin\OfferController::tabMine(auth()->user())" :fallback="$fallback" id="offers-tabs"/>
    @isset($actions)
        <div class="title-actions">{{ $actions }}</div>
    @endisset
</div>
<x-ui.toolbar rows :sort="$sort" :name="$name" :facets="$facets" :search="$search">
    @if ($views)
        <x-slot:extra><x-ui.view-switch :current="$view" :views="$views"/></x-slot:extra>
    @endif
    {{-- «Выбрать» (22.11.2026, начальник: галочки — только после неё, как в iOS) — под лупой, сортировкой и видом слева, в одной
         строке с быстрыми чипами (владелец); капсулой 36 px того же вида, что «Фильтр» (владелец: «Выбрать огромная»). Режим выбора списка
         (`pick_controller#mode`), в режиме — лаймовое «Готово». --}}
    @if ($select)
        <x-slot:under><button type="button" class="pill toolbar-pick" data-controller="emit" data-action="emit#send" data-emit-event-param="pick:mode" data-pick-toggle aria-pressed="false"><x-ui.icon name="check-circle" class="size-4 shrink-0"/><span data-pick-toggle-label>Выбрать</span></button></x-slot:under>
    @endif
</x-ui.toolbar>
