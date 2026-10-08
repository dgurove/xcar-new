{{-- Галерея CRM (08.10.2026): «В галерее» — черновики в окне показа, когда появились и сколько осталось; «Отклики» —
     у кого есть интерес, кто и что — в карточке строки. Только таблица. --}}
<x-ui.shell title="Галерея" :count="$offers->total()" :phone-heading="false" :detail="$detail">
    <x-ui.toolbar :sort="$sort" :pills="\App\Http\Admin\GalleryController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" name="gallery" :facets="$facets" search="Номер, марка, VIN, убыток"/>

    <div class="mt-6" id="list">
        @if ($offers->isEmpty())
            <x-ui.empty>{{ $searching ? 'Ничего не нашлось' : ($preset === 'interest' ? 'Откликов нет' : 'В галерее пусто') }}</x-ui.empty>
        @else
            <x-ui.table id="gallery" :view="\App\Support\ListView::TABLE" :titles="$offers->getCollection()->map->titleWithYear()" :rest="38">
                <x-slot:head><x-offer.gallery-head :sort="$sort"/></x-slot:head>
                @foreach ($offers as $offer)<x-offer.gallery-row :offer="$offer"/>@endforeach
            </x-ui.table>
        @endif
    </div>
    {{-- Окно писем для карточки «Письма» в карточке строки. --}}
    <x-mail.window/>
</x-ui.shell>
