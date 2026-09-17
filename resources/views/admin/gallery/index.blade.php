<x-ui.shell title="Галерея" :count="$offers->total()">
    <x-ui.toolbar :sorts="\App\Http\Admin\GalleryController::SORTS" :sort="$sort" name="gallery">
        <x-slot:extra>
            <x-ui.view-switch/>
            <form method="post" action="/gallery" class="shrink-0">@csrf<button type="submit" class="btn btn-s btn-accent rounded-full"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Новый</span></button></form>
        </x-slot:extra>
        <x-slot:filters>
            <input name="q" value="{{ request('q') }}" placeholder="Номер, марка, VIN" class="field-input field-s">
        </x-slot:filters>
    </x-ui.toolbar>

    <div class="mt-6">
        @if ($offers->isEmpty())
            <x-ui.empty>В галерее пусто</x-ui.empty>
        @else
            @php $view = \App\Support\ListView::fromRequest(request()); @endphp
            @if ($view === \App\Support\ListView::TABLE)
                <x-ui.table id="gallery">
                    <x-slot:head><x-offer.table-head gallery/></x-slot:head>
                    @foreach ($offers as $offer)<x-offer.table-row :offer="$offer" gallery/>@endforeach
                </x-ui.table>
            @else
            <div id="gallery" class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
                @foreach ($offers as $offer)<x-offer.card :offer="$offer" admin/>@endforeach
            </div>
            @endif
            <div class="mt-8">{{ $offers->links() }}</div>
        @endif
    </div>
</x-ui.shell>
