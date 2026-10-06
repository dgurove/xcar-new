@php $view = \App\Support\ListView::pick(request(), $offers->total()); @endphp
<x-ui.cabinet title="Избранное" :detail="$detail">
    @if ($offers->isEmpty())
        <x-ui.empty href="/offers" link="В предложения">Пока пусто</x-ui.empty>
    @else
        <x-ui.toolbar :sort="$sort" name="favorites"><x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra></x-ui.toolbar>
        @if (\App\Support\ListView::isTable($view))
        <x-ui.table :view="$view">
            <x-slot:head><x-offer.site-table-head :sort="$sort"/></x-slot:head>
            @foreach ($offers as $offer)<x-offer.site-table-row :offer="$offer"/>@endforeach
        </x-ui.table>
        @else
        <div class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
            @foreach ($offers as $offer)<x-offer.card :offer="$offer"/>@endforeach
        </div>
        @endif
        <x-ui.pager :of="$offers" :sizes="\App\Support\ListView::perSizes($view)"/>
    @endif
</x-ui.cabinet>
