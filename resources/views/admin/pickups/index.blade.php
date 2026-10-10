{{-- «Работа → Вывоз»: кто что вывозит — группами по ответственному («Мы» — последней), в четырёх видах, как «Гараж»:
     таблица (по умолчанию), подробная, плитки и строки (`x-garage.card`: шаг вывоза словом и дни, вендор, справа куда).
     Нажатие — карточка: путь вывоза с кнопками, «Кто и куда вывозит»; «Открыть страницу» — редактор предложения. --}}
@php use App\Support\ListView; $view = ListView::pick(request(), $total); @endphp
<x-ui.shell title="Вывоз" :heading="false" :detail="$detail">
    <x-admin.work-titles current="pickups" :count="$total"/>
    <x-ui.toolbar class="mt-5" :sort="$sort" :pills="\App\Http\Admin\PickupController::PRESETS" :pill="$preset" pill-param="preset" name="pickups" :facets="$facets">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
    </x-ui.toolbar>

    @if ($groups->isEmpty())
        <x-ui.empty class="mt-6">Вывозить нечего</x-ui.empty>
    @elseif (ListView::isTable($view))
        <x-ui.table id="pickups-table" class="mt-4" :view="$view" :titles="$groups->flatten()->map->titleWithYear()">
            <x-slot:head>
                <tr>
                    <th class="grow">Марка, модель</th>
                    <th class="hidden sm:table-cell">Вендор, № убытка</th>
                    <th class="hidden lg:table-cell col-detail-hide">Город</th>
                    <x-ui.th :sort="$sort" key="step" class="num hidden xl:table-cell col-detail-hide">На шаге с</x-ui.th>
                    <th class="num">Куда</th>
                </tr>
            </x-slot:head>
            @foreach ($groups as $offers)
                <tr class="table-group"><th colspan="5"><span class="table-group-name">{{ $offers->first()->evacuator?->name ?? 'Мы' }} <span class="nums">{{ $offers->count() }}</span></span></th></tr>
                @foreach ($offers as $offer)<x-admin.pickup-row :offer="$offer"/>@endforeach
            @endforeach
        </x-ui.table>
    @else
        <div class="mt-4 flex flex-col">
            @foreach ($groups as $offers)
                <h2 class="list-head">{{ $offers->first()->evacuator?->name ?? 'Мы' }}<span class="nums text-base font-normal text-ink-muted">{{ $offers->count() }}</span></h2>
                <div class="{{ ListView::containerClass($view) }} mb-6" data-controller="ticker">
                    @foreach ($offers as $offer)
                        @php [$word, $tone, $days] = \App\Offers\PickupState::crmLine($offer); @endphp
                        <x-garage.card :offer="$offer" :href="\App\Support\Detail::url($offer->number)" detail vendor :word="$word" :tone="$tone" :when="$days" :note="mb_strtolower($offer->pickupDestination()->label())"/>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</x-ui.shell>
