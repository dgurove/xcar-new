{{-- «Гараж» — свой раздел колеса «Предложений» (16.11.2026, `GarageController::tab`): все гаражные машины до расчёта, группами по
     этапу сделки (как во «Все»), сделка закрыта — по этапу гаража; менеджер — столбцом. Виды — как у остальных списков CRM:
     таблица (по умолчанию), подробная, ленты, плитки и строки (`x-garage.card`). Нажатие — окно машины: путь, деньги, расходы и
     действия. Заголовок — колесо вкладок «Предложений» с разделами. --}}
@php
    use App\Support\ListView;
    $view = ListView::pick(request(), $total, shelves: true);
    $user = auth()->user();
@endphp
<x-ui.shell :title="$presets['garage'] ?? 'Гараж'" :heading="false" :detail="$detail">
    <div class="title-row">
        <x-ui.tab-title :pills="$presets" pill="garage" pill-param="preset" pill-home="" :counts="$counts" :badges="\App\Http\Admin\OfferController::badges()" :bumps="['legacy' => 'legacy_drafts']" :sections="$sections" :drop="['todo', 'what', 'sub', 'turn']" fallback="Предложения" id="offers-tabs"/>
    </div>
    <x-ui.toolbar :sort="$sort" name="garage" :facets="$facets">
        <x-slot:extra><x-ui.view-switch :current="$view" :views="ListView::SHELVED"/></x-slot:extra>
    </x-ui.toolbar>

    @if ($groups->isEmpty())
        <x-ui.empty class="mt-6">В гараже пусто</x-ui.empty>
    @elseif (ListView::isTable($view))
        @php $columns = \App\Http\Admin\GarageController::table(); @endphp
        <x-ui.table :table="$columns" id="garage-table" class="mt-4" :view="$view">
            <x-slot:head><x-table.head :table="$columns" :sort="$sort"/></x-slot:head>
            @foreach ($groups as $key => $cars)
                <x-table.group :table="$columns" :key="'g-'.str_replace(':', '-', $key)" :title="\App\Http\Admin\GarageController::groupTitle($key)" :count="$cars->count()"/>
                @foreach ($cars as $car)<x-admin.garage-row :car="$car" :table="$columns"/>@endforeach
            @endforeach
        </x-ui.table>
    @elseif ($view === ListView::GRID)
        {{-- «Плитками» (15.10.2026, начальник): одна сетка без подписей менеджеров. --}}
        <div class="cards cards--grid mt-4" data-controller="ticker">
            @foreach ($groups->flatten() as $car)
                @php $line = \App\Garage\CarLine::for($car, $user, crm: true); @endphp
                <x-garage.card :offer="$car->offer" :href="\App\Support\Detail::url($car->offer->number)" detail vendor
                               :word="$line->word" :tone="$line->tone" :when="$line->when" :value="$line->value" :caption="$line->caption"/>
            @endforeach
        </div>
    @else
        <div class="mt-4 flex flex-col">
            @foreach ($groups as $key => $cars)
                @php $who = \App\Http\Admin\GarageController::groupTitle($key); @endphp
                {{-- «Лентами» пачка менеджера — лентой вбок (x-ui.shelf, 11.10.2026), строками — как была. --}}
                @if ($view === ListView::SHELF)
                    <x-ui.shelf :title="$who" :count="$cars->count()" data-controller="ticker">
                        @foreach ($cars as $car)
                            @php $line = \App\Garage\CarLine::for($car, $user, crm: true); @endphp
                            <x-garage.card :offer="$car->offer" :href="\App\Support\Detail::url($car->offer->number)" detail vendor
                                           :word="$line->word" :tone="$line->tone" :when="$line->when" :value="$line->value" :caption="$line->caption"/>
                        @endforeach
                    </x-ui.shelf>
                    @continue
                @endif
                <h2 class="list-head">{{ $who }}<span class="nums text-base font-normal text-ink-muted">{{ $cars->count() }}</span></h2>
                <div class="{{ ListView::containerClass($view) }} mb-6" data-controller="ticker">
                    @foreach ($cars as $car)
                        @php $line = \App\Garage\CarLine::for($car, $user, crm: true); @endphp
                        <x-garage.card :offer="$car->offer" :href="\App\Support\Detail::url($car->offer->number)" detail vendor
                                       :word="$line->word" :tone="$line->tone" :when="$line->when" :value="$line->value" :caption="$line->caption"/>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
    {{-- Окно писем для письма поставщику в пути сделки карточки. --}}
    <x-mail.window/>
</x-ui.shell>
