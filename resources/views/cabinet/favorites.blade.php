<x-ui.cabinet title="Избранное" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Избранное']]">
    @if ($offers->isEmpty())
        <x-ui.empty href="/" link="В предложения">Пока пусто.</x-ui.empty>
    @else
        <div class="mb-4 flex justify-end"><x-ui.view-switch/></div>
        <div class="{{ \App\Support\ListView::containerClass(\App\Support\ListView::fromRequest(request())) }}" data-controller="ticker">
            @foreach ($offers as $offer)<x-offer.card :offer="$offer"/>@endforeach
        </div>
        <div class="mt-8">{{ $offers->links() }}</div>
    @endif
</x-ui.cabinet>
