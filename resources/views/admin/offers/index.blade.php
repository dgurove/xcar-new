<x-ui.shell title="Предложения" :count="$offers->total()">
    <x-ui.toolbar :sorts="\App\Http\Admin\OfferController::SORTS" :sort="$sort" :pills="\App\Http\Admin\OfferController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" name="offers">
        <x-slot:extra>
            <x-ui.view-switch/>
            <form method="post" action="/predlozheniya" class="shrink-0">@csrf<button type="submit" class="btn btn-s btn-accent rounded-full"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Новый</span></button></form>
            <a href="/predlozheniya/iz-pisem" class="btn btn-s btn-quiet relative shrink-0 rounded-full" aria-label="Из писем"><x-ui.icon name="mail" class="size-4"/><span class="hidden sm:inline">Из писем</span><x-ui.badge href="/predlozheniya/iz-pisem" :badges="\App\Support\Nav::badges(auth()->user())"/></a>
        </x-slot:extra>
        <x-slot:filters>
            <input name="q" value="{{ request('q') }}" placeholder="Номер, марка, VIN" class="field-input field-s">
        </x-slot:filters>
    </x-ui.toolbar>

    <div class="mt-6">
        @if ($offers->isEmpty())
            <x-ui.empty>Предложений нет.</x-ui.empty>
        @else
            <div id="offers" class="{{ \App\Support\ListView::containerClass(\App\Support\ListView::fromRequest(request())) }}" data-controller="ticker">
                @foreach ($offers as $offer)<x-offer.card :offer="$offer" admin/>@endforeach
            </div>
            <div class="mt-8">{{ $offers->links() }}</div>
        @endif
    </div>
</x-ui.shell>
