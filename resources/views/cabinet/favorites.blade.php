@php $view = \App\Support\ListView::pick(request(), $offers->total()); @endphp
<x-ui.cabinet title="Избранное">
    @if ($offers->isEmpty())
        <x-ui.empty href="/" link="В предложения">Пока пусто</x-ui.empty>
    @else
        <div class="flex justify-end"><x-ui.view-switch :current="$view"/></div>
        @if ($view === \App\Support\ListView::TABLE)
        <x-ui.table>
            <x-slot:head><x-offer.site-table-head/></x-slot:head>
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
