{{-- «Ждут решения» — оценённое менеджером, решение за XCar (11.10.2026, владелец): последней пачкой каталога, плитками лентой
     вбок, как «Нужно оценить», без полосы — делать там нечего; таблицей — строками с заголовком группы. --}}
@if ($estimated->isNotEmpty())
    @if (\App\Support\ListView::isTable($view))
        <x-ui.table id="catalog-estimated" :view="$view" class="mt-6">
            <x-slot:head><x-table.head :table="$columns" :sort="$sorting"/></x-slot:head>
            <x-table.group :table="$columns" key="estimated" title="Ждут решения" :count="$estimated->count()"/>
            @foreach ($estimated as $offer)
                <x-offer.site-table-row :offer="$offer" :table="$columns" :estimate="$offer->openEstimates->first()"/>
            @endforeach
        </x-ui.table>
    @else
        <div class="mt-6" data-controller="ticker">
            <x-ui.shelf title="Ждут решения" :count="$estimated->count()" id="catalog-estimated">
                @foreach ($estimated as $offer)
                    <x-offer.card :offer="$offer" :estimate="$offer->openEstimates->first()" cover/>
                @endforeach
            </x-ui.shelf>
        </div>
    @endif
@endif
