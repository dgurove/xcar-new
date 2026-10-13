{{-- Интерес в дорожке редактора: карточкой «Интерес N» с группой строк `x-offer.interests`. У черновика в галерее — карточка
     «Галерея»: сначала до какого времени он там, потом интерес (13.10.2026). --}}
@php $gallery = $offer->isGallery(); $n = $offer->interests->count(); @endphp
<x-ui.card :title="$gallery ? 'Галерея' : 'Интерес'.($n ? ' '.$n : '')">
    @if ($gallery)
        <div class="detail-line mb-3"><span>до {{ $offer->gallery_until->translatedFormat('j M, H:i') }}</span><x-offer.gallery-left :offer="$offer"/></div>
    @endif
    <x-offer.interests :offer="$offer"/>
</x-ui.card>
