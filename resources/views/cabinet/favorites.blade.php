@php $view = \App\Support\ListView::pick(request(), $offers->total()); @endphp
<x-ui.cabinet title="Избранное" :detail="$detail">
    @if ($offers->isEmpty())
        <x-ui.empty href="/offers" link="В предложения">Пока пусто</x-ui.empty>
    @else
        <x-ui.toolbar :sort="$sort" name="favorites"><x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra></x-ui.toolbar>
        @if (\App\Support\ListView::isTable($view))
        @php $columns = \App\Http\Site\CatalogController::table(auth()->user()?->bidsOnSite() ?? false, $sort); @endphp
        <x-ui.table :view="$view">
            <x-slot:head><x-table.head :table="$columns" :sort="$sort"/></x-slot:head>
            @foreach ($offers as $offer)<x-offer.site-table-row :offer="$offer" :table="$columns"/>@endforeach
        </x-ui.table>
        @else
        <div class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
            @foreach ($offers as $offer)<x-offer.card :offer="$offer"/>@endforeach
        </div>
        @endif
        <x-ui.pager :of="$offers" :sizes="\App\Support\ListView::perSizes($view)"/>
    @endif
</x-ui.cabinet>
