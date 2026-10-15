{{-- «Предложения» вендора в CRM (15.10.2026): все его предложения за всё время, архив тоже. Чипы «К запросу» (есть отклик, ещё продаётся,
     номер не отправляли), «Отклик» (галки: подтверждения, интерес, без отклика — через «или»), «Запрос», «Где сейчас», «Заведено»; лупа, сортировка заголовками; «Скачать» — то, что на экране, файлом
     (`VendorOffersExport`). Строка ведёт в редактор предложения. --}}
<x-ui.toolbar :sort="$sort" name="vendor-offers" :facets="$facets" search="Номер, марка, VIN, убыток">
    <x-slot:actions>
        {{-- «Отправить N номеров» (15.10.2026): ещё не отправленные номера всего списка (все страницы) — столбиком в буфер в
             момент нажатия, метка «запрос отправлен» и номера в Telegram отправившему (`MarkRequested`). --}}
        @if ($pending->isNotEmpty())
            @php $n = $pending->count(); @endphp
            <form method="post" action="{{ $base }}/offers/request" class="contents">
                @csrf
                @foreach ($pending as $o)<input type="hidden" name="offers[]" value="{{ $o->id }}">@endforeach
                <button class="btn btn-s btn-accent toolbar-wide shrink-0 rounded-full" data-controller="copy" data-copy-text-value="{{ $pending->pluck('claim_ref')->implode(PHP_EOL) }}" data-copy-done-value="Номера убытка в буфере" data-action="copy#copy"><x-ui.icon name="send" class="size-4"/>Отправить {{ $n }} {{ \App\Support\Plural::of($n, ['номер', 'номера', 'номеров']) }}</button>
            </form>
        @endif
        <a href="{{ $base }}/offers/export?{{ http_build_query(request()->except(['pill', 'page'])) }}" class="btn btn-s btn-quiet btn-round shrink-0" download data-turbo="false" aria-label="Скачать таблицей" title="Скачать таблицей"><x-ui.icon name="download" class="size-5"/></a>
    </x-slot:actions>
</x-ui.toolbar>
<div class="mt-4" id="list">
    @if ($offers->isEmpty())
        <x-ui.empty>{{ $searching || $facets->active() ? 'Ничего не нашлось' : 'Предложений нет' }}</x-ui.empty>
    @else
        <div data-controller="row-go" data-action="click->row-go#go">
            <x-ui.table id="vendor-offers" :view="\App\Support\ListView::TABLE" :titles="$offers->getCollection()->map->titleWithYear()" :rest="36">
                <x-slot:head>
                    <tr>
                        <th class="grow title-cap">Марка, модель</th>
                        <th class="hidden sm:table-cell">№ убытка</th>
                        <th class="hidden md:table-cell">Где</th>
                        <x-ui.th :sort="$sort" key="bids" class="num">Подтв.</x-ui.th>
                        <x-ui.th :sort="$sort" key="interest" class="num">Интерес</x-ui.th>
                        <x-ui.th :sort="$sort" key="floor" class="num hidden sm:table-cell">Закупочная</x-ui.th>
                        <x-ui.th :sort="$sort" key="price" class="num hidden sm:table-cell">Продажа</x-ui.th>
                        <x-ui.th :sort="$sort" key="requested" class="num hidden sm:table-cell">Запрос</x-ui.th>
                    </tr>
                </x-slot:head>
                @foreach ($offers as $offer)<x-vendor.offer-row :offer="$offer" :base="$base"/>@endforeach
            </x-ui.table>
        </div>
        @if ($offers->hasPages())<div class="mt-6"><x-ui.pager :of="$offers"/></div>@endif
    @endif
</div>
