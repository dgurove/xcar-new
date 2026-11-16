{{-- Галерея CRM (08.10.2026): «В галерее» — черновики в окне показа, когда появились и сколько осталось; «Отклики» —
     у кого есть интерес, кто и что — в карточке строки. Только таблица. С 16.11.2026 — раздел «Галерея» колеса «Предложений»
     (`GalleryController::tab`): заголовок — колесо вкладок, свои пилюли ушли в него. --}}
<x-ui.shell :title="$presets[$preset] ?? 'Галерея'" :heading="false" :detail="$detail">
    <div class="title-row">
        <x-ui.tab-title :pills="$presets" :pill="$preset" pill-param="preset" pill-home="" :counts="$counts" :badges="\App\Http\Admin\OfferController::badges()" :bumps="['legacy' => 'legacy_drafts']" :sections="$sections" :drop="['todo', 'what', 'sub', 'turn']" fallback="Предложения" id="offers-tabs"/>
    </div>
    <x-ui.toolbar :sort="$sort" name="gallery" :facets="$facets" search="Номер, марка, VIN, убыток"/>

    <div class="mt-6" id="list">
        @if ($offers->isEmpty())
            <x-ui.empty>{{ $searching ? 'Ничего не нашлось' : ($preset === 'interest' ? 'Откликов нет' : 'В галерее пусто') }}</x-ui.empty>
        @else
            @php $columns = \App\Http\Admin\GalleryController::table(); @endphp
            <x-ui.table :table="$columns" id="gallery" :view="\App\Support\ListView::TABLE" :portion="$offers">
                <x-slot:head><x-table.head :table="$columns" :sort="$sort"/></x-slot:head>
                @foreach ($offers as $offer)<x-offer.gallery-row :offer="$offer" :table="$columns"/>@endforeach
            </x-ui.table>
        @endif
    </div>
    {{-- Окно писем для карточки «Письма» в карточке строки. --}}
    <x-mail.window/>
</x-ui.shell>
