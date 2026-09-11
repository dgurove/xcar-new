<x-ui.shell title="Офферы" :wide="true">
    <div class="mb-4 flex items-center gap-3">
        <x-ui.presets :items="\App\Http\Admin\OfferController::PRESETS" :current="$preset" :counts="$counts"/>
        <x-ui.sort :items="\App\Http\Admin\OfferController::SORTS" :current="$sort"/>
        <form method="post" action="/admin/offers" class="ml-auto shrink-0">@csrf<x-ui.button size="sm"><x-ui.icon name="plus" class="size-4"/> Новый</x-ui.button></form>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" id="offers">
        @foreach ($offers as $offer)
            <x-offer.admin-tile :offer="$offer"/>
        @endforeach
    </div>
    <div class="mt-4">{{ $offers->links() }}</div>
</x-ui.shell>
