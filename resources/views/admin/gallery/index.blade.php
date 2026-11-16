{{-- Галерея CRM (08.10.2026): «В галерее» — черновики в окне показа, когда появились и сколько осталось; «Отклики» —
     у кого есть интерес, кто и что — в карточке строки. Только таблица. С 16.11.2026 — раздел «Галерея» колеса «Предложений»
     (`GalleryController::tab`): заголовок — колесо вкладок, свои пилюли ушли в него. --}}
<x-ui.shell :title="$presets[$preset] ?? 'Галерея'" :heading="false" :detail="$detail">
    {{-- Шапка — одна на вкладки колеса (`x-admin.tab-head`, 17.11.2026). --}}
    <x-admin.tab-head :presets="$presets" :preset="$preset" :counts="$counts" :sections="$sections" :sort="$sort" name="gallery" :facets="$facets" search="Номер, марка, VIN, убыток"/>

    <div class="mt-6" id="list">
        @if ($offers->isEmpty())
            @if (! $searching && $facets->activeCount())
                <x-ui.empty :href="$facets->resetUrl()" link="Сбросить фильтр">По фильтру ничего</x-ui.empty>
            @else
                <x-ui.empty>{{ $searching ? 'Ничего не нашлось' : ($preset === 'interest' ? 'Откликов нет' : 'В галерее пусто') }}</x-ui.empty>
            @endif
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
