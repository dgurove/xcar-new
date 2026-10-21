{{-- «Ждут решения» — оценённое менеджером, решение за XCar (11.10.2026, владелец): последней пачкой каталога, плитками лентой
     вбок, как «Нужно оценить», без полосы — делать там нечего; таблицей — строками с заголовком группы. --}}
@if ($estimated->isNotEmpty())
    @if (\App\Support\ListView::isTable($view))
        <x-ui.table id="catalog-estimated" :view="$view" class="mt-6">
            <x-slot:head><x-offer.site-table-head :sort="$sorting" :deadline="$deadline"/></x-slot:head>
            <tr class="table-group"><th colspan="99" scope="colgroup">Ждут решения <span class="nums">{{ $estimated->count() }}</span></th></tr>
            @foreach ($estimated as $offer)
                <x-offer.site-table-row :offer="$offer" :deadline="$deadline" :estimate="$offer->openEstimates->first()"/>
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
