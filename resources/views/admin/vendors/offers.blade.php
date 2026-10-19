{{-- «Предложения» вендора в CRM (15.10.2026): все его предложения за всё время, архив тоже. Чипы «Отклик» (галки: подтверждения, интерес,
     без отклика — через «или»), «Где сейчас», «Запрос», «Письмо», «Заведено»; ежедневная отправка номеров — пилюля «Запросы»; лупа, сортировка заголовками; «Скачать» — то, что на экране, файлом
     (`VendorOffersExport`). Строка ведёт в редактор предложения. --}}
<x-ui.toolbar :sort="$sort" name="vendor-offers" :facets="$facets" search="Номер, марка, VIN, убыток">
    <x-slot:actions>
        <a href="{{ $base }}/offers/export?{{ http_build_query(request()->except(['pill', 'page'])) }}" class="btn btn-s btn-quiet btn-round shrink-0" download data-turbo="false" aria-label="Скачать таблицей" title="Скачать таблицей"><x-ui.icon name="download" class="size-5"/></a>
    </x-slot:actions>
</x-ui.toolbar>
<div class="mt-4" id="list">
    @if ($offers->isEmpty())
        <x-ui.empty>{{ $searching || $facets->active() ? 'Ничего не нашлось' : 'Предложений нет' }}</x-ui.empty>
    @else
        @php $withBinding = $offers->getCollection()->contains(fn ($o) => $o->binding_until !== null); @endphp
        <div data-controller="row-go" data-action="click->row-go#go">
            <x-ui.table id="vendor-offers" :view="\App\Support\ListView::TABLE" :titles="$offers->getCollection()->map->titleWithYear()" :rest="36">
                <x-slot:head>
                    <tr>
                        <th class="grow title-cap">Марка, модель</th>
                        <th class="hidden sm:table-cell">№ убытка</th>
                        <th class="hidden md:table-cell">Где</th>
                        {{-- Срок обязывающего — столбцом, только когда он есть хоть у одной строки (19.10.2026). --}}
                        @if ($withBinding)<x-ui.th :sort="$sort" key="binding" class="hidden md:table-cell">Обязывающее до</x-ui.th>@endif
                        <x-ui.th :sort="$sort" key="bids" class="num">Подтв.</x-ui.th>
                        <x-ui.th :sort="$sort" key="interest" class="num">Интерес</x-ui.th>
                        <x-ui.th :sort="$sort" key="floor" class="num hidden sm:table-cell">Закупочная</x-ui.th>
                        <x-ui.th :sort="$sort" key="price" class="num hidden sm:table-cell">Продажа</x-ui.th>
                        <x-ui.th :sort="$sort" key="requested" class="num hidden sm:table-cell">Запрос</x-ui.th>
                    </tr>
                </x-slot:head>
                @foreach ($offers as $offer)<x-vendor.offer-row :offer="$offer" :base="$base" :binding="$withBinding"/>@endforeach
            </x-ui.table>
        </div>
        @if ($offers->hasPages())<div class="mt-6"><x-ui.pager :of="$offers"/></div>@endif
    @endif
</div>
