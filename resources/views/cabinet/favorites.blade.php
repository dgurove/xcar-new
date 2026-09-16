<x-ui.cabinet title="Избранное">
    @if ($offers->isEmpty())
        <x-ui.empty href="/" link="В предложения">Пока пусто.</x-ui.empty>
    @else
        <div class="flex justify-end"><x-ui.view-switch/></div>
        <div class="{{ \App\Support\ListView::containerClass(\App\Support\ListView::fromRequest(request())) }}" data-controller="ticker">
            @foreach ($offers as $offer)<x-offer.card :offer="$offer"/>@endforeach
        </div>
        {{ $offers->links() }}
    @endif
</x-ui.cabinet>
