<x-ui.shell title="Офферы" :count="$offers->total()" :trail="[['Главная', '/'], ['Офферы']]">
    <x-ui.toolbar :sorts="\App\Http\Admin\OfferController::SORTS" :sort="$sort" :pills="\App\Http\Admin\OfferController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" name="offers">
        <x-slot:extra>
            <x-ui.view-switch/>
            <form method="post" action="/admin/offers" class="shrink-0">@csrf<button type="submit" class="btn btn-s btn-accent rounded-full"><x-ui.icon name="plus" class="size-4"/> Новый</button></form>
        </x-slot:extra>
        <x-slot:filters>
            <input name="q" value="{{ request('q') }}" placeholder="Номер, марка, VIN" class="field-input field-s">
        </x-slot:filters>
    </x-ui.toolbar>

    <div class="mt-6">
        @if ($offers->isEmpty())
            <x-ui.empty>Офферов нет.</x-ui.empty>
        @else
            <div id="offers" class="{{ \App\Support\ListView::containerClass(\App\Support\ListView::fromRequest(request())) }}" data-controller="ticker">
                @foreach ($offers as $offer)<x-offer.card :offer="$offer" admin/>@endforeach
            </div>
            <div class="mt-8">{{ $offers->links() }}</div>
        @endif
    </div>
</x-ui.shell>
