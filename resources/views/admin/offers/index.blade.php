<x-ui.shell title="Предложения" :count="$offers->total()">
    <x-ui.toolbar :sorts="\App\Http\Admin\OfferController::SORTS" :sort="$sort" :pills="\App\Http\Admin\OfferController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" name="offers">
        <x-slot:extra>
            <x-ui.view-switch/>
            <form method="post" action="/offers" class="shrink-0">@csrf<button type="submit" class="btn btn-s btn-accent rounded-full"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Новый</span></button></form>
            <a href="/offers/from-mail" class="btn btn-s btn-quiet relative shrink-0 rounded-full" aria-label="Из писем"><x-ui.icon name="mail" class="size-4"/><span class="hidden sm:inline">Из писем</span><x-ui.badge href="/offers/from-mail" :badges="\App\Support\Nav::badges(auth()->user())"/></a>
        </x-slot:extra>
        <x-slot:filters>
            <input name="q" value="{{ request('q') }}" placeholder="Номер, марка, VIN" class="field-input field-s">
        </x-slot:filters>
    </x-ui.toolbar>

    <div class="mt-6">
        @if ($offers->isEmpty())
            <x-ui.empty>Предложений нет</x-ui.empty>
        @else
            @php $view = \App\Support\ListView::fromRequest(request()); @endphp
            @if ($view === \App\Support\ListView::TABLE)
                <x-ui.table id="offers">
                    <x-slot:head><x-offer.table-head/></x-slot:head>
                    @foreach ($offers as $offer)<x-offer.table-row :offer="$offer"/>@endforeach
                </x-ui.table>
            @else
            <div id="offers" class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
                @foreach ($offers as $offer)<x-offer.card :offer="$offer" admin/>@endforeach
            </div>
            @endif
            <div class="mt-8">{{ $offers->links() }}</div>
        @endif
    </div>
</x-ui.shell>
