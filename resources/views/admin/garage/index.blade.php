{{-- «Работа → Гараж»: у кого что стоит и во что обошлось — группами по менеджеру (взятые под себя — последними), в четырёх
     видах, как остальные списки CRM: таблица (по умолчанию), подробная, ленты, плитки и строки (`x-garage.card`). Нажатие — карточка
     рядом: путь машины, деньги, расходы и действия; «Открыть страницу» — редактор предложения с карточкой «Гараж». --}}
@php
    use App\Support\ListView;
    $view = ListView::pick(request(), $total, shelves: true);
    $user = auth()->user();
@endphp
<x-ui.shell title="Гараж" :heading="false" :detail="$detail">
    <x-admin.work-titles current="garage" :count="$total"/>
    <x-ui.toolbar class="mt-5" :sort="$sort" :pills="\App\Http\Admin\GarageController::PRESETS" :pill="$preset" pill-param="preset" name="garage" :facets="$facets">
        <x-slot:extra><x-ui.view-switch :current="$view" :views="ListView::SHELVED"/></x-slot:extra>
    </x-ui.toolbar>

    @if ($groups->isEmpty())
        <x-ui.empty class="mt-6">В гараже пусто</x-ui.empty>
    @elseif (ListView::isTable($view))
        <x-ui.table id="garage-table" class="mt-4" :view="$view" :titles="$groups->flatten()->map(fn ($c) => $c->offer->titleWithYear())">
            <x-slot:head>
                <tr>
                    <th class="grow">Марка, модель</th>
                    <th class="hidden sm:table-cell">Вендор, № убытка</th>
                    <th class="num hidden xl:table-cell col-detail-hide">В гараже с</th>
                    <x-ui.th :sort="$sort" key="price" class="num hidden sm:table-cell">Закупочная</x-ui.th>
                    <th class="num hidden sm:table-cell">Расходы</th>
                    <th class="num hidden sm:table-cell">Цена продажи</th>
                    <th class="num sm:hidden">Сумма</th>
                </tr>
            </x-slot:head>
            @foreach ($groups as $cars)
                <tr class="table-group"><th colspan="7"><span class="table-group-name">{{ $cars->first()->manager?->name ?? 'Взяли под себя' }} <span class="nums">{{ $cars->count() }}</span></span></th></tr>
                @foreach ($cars as $car)<x-admin.garage-row :car="$car"/>@endforeach
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
            @foreach ($groups as $cars)
                @php $who = $cars->first()->manager?->name ?? 'Взяли под себя'; @endphp
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
