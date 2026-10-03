@php $view = \App\Support\ListView::pick(request(), $offers->total()); @endphp
<x-ui.shell title="Галерея" :count="$offers->total()" :phone-heading="false" :detail="$detail">
    <x-ui.toolbar :sorts="\App\Http\Admin\GalleryController::SORTS" :sort="$sort" name="gallery" :facets="$facets" search="Номер, марка, VIN, убыток">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
        <x-slot:actions>
            <form method="post" action="/gallery" class="shrink-0">@csrf<button type="submit" class="btn btn-s btn-accent rounded-full"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Новый</span></button></form>
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="mt-6" id="list">
        @if ($offers->isEmpty())
            <x-ui.empty>В галерее пусто</x-ui.empty>
        @else
            @if (\App\Support\ListView::isTable($view))
                <x-ui.table id="gallery" :view="$view">
                    <x-slot:head><x-offer.table-head gallery/></x-slot:head>
                    @foreach ($offers as $offer)<x-offer.table-row :offer="$offer" gallery/>@endforeach
                </x-ui.table>
            @else
            <div id="gallery" class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
                @foreach ($offers as $offer)<x-offer.crm-card :offer="$offer" gallery/>@endforeach
            </div>
            @endif
            <div class="mt-8"><x-ui.pager :of="$offers" :sizes="\App\Support\ListView::perSizes($view)"/></div>
        @endif
    </div>
    {{-- Окно писем для карточки «Письма» в окошке строки. --}}
    <x-mail.window/>
</x-ui.shell>
